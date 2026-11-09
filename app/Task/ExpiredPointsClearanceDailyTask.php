<?php

declare(strict_types=1);

namespace App\Task;

use Throwable;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use App\Constants\OrderCode;
use App\Model\Member;
use App\Model\MemberPointsLog;
use App\Model\MemberOrderDetail;
use App\Service\LoggerService;

class ExpiredPointsClearanceDailyTask
{
    #[Inject]
    protected LoggerService $loggerService;

    /**
     * 每次从详情表扫描的记录数，避免一次性把全部过期记录读进内存
     */
    private const CHUNK_SIZE = 200;

    #[Crontab(name: "DailyTask", rule: "0 2 * * *", callback: "execute", memo: "每天凌晨2点执行")]
    public function execute(): void
    {
        // 每天清理过期的积分
        $this->loggerService->info('过期积分清理任务开始');

        $total = 0;
        $expired = 0;
        $conflicted = 0;
        $failed = 0;

        try {
            // 只处理状态为「激活」且已过期的详情，按主键游标分批扫描
            // （用 chunkById 而非 chunk：本任务会改动 status，游标法不会因结果集变化而漏记录）
            MemberOrderDetail::query()
                ->from('member_order_details as d')
                ->join('member_order_master as m', 'd.order_master_id', '=', 'm.order_master_id')
                ->whereNull('m.deleted_at')
                ->where('d.status', OrderCode::DETAIL_STATUS_ACTIVE)
                ->whereNotNull('d.expiration_date')
                ->where('d.expiration_date', '<', date('Y-m-d H:i:s'))
                ->select([
                    'd.order_details_id as order_details_id',
                    'd.order_master_id as order_master_id',
                    'd.member_id as member_id',
                    'd.change_points as change_points',
                    'd.version as version',
                    'm.order_no as order_no',
                ])
                ->chunkById(self::CHUNK_SIZE, function ($details) use (&$total, &$expired, &$conflicted, &$failed) {
                    foreach ($details as $detail) {
                        ++$total;

                        // 每条详情独立事务：单条失败不影响其余记录
                        try {
                            $result = $this->clearExpiredDetail($detail);

                            if ($result === 'expired') {
                                ++$expired;
                            } else {
                                ++$conflicted;
                            }
                        } catch (Throwable $e) {
                            ++$failed;

                            $this->loggerService->error('过期积分清理失败：单条详情处理异常', [
                                'order_details_id' => $detail->order_details_id,
                                'order_master_id' => $detail->order_master_id,
                                'member_id' => $detail->member_id,
                                'change_points' => $detail->change_points,
                                'version' => $detail->version,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    }
                }, 'd.order_details_id', 'order_details_id');

            $this->loggerService->info('过期积分清理任务结束', [
                'total' => $total,
                'expired' => $expired,
                'conflicted' => $conflicted,
                'failed' => $failed,
            ]);
        } catch (Throwable $e) {
            // 扫描阶段异常（连接失败、SQL 错误等），记录后不再抛出：
            // 定时任务没有重试机制，抛出只会让任务进程报错，问题仍需依靠日志排查
            $this->loggerService->error('过期积分清理任务执行异常', [
                'total' => $total,
                'expired' => $expired,
                'conflicted' => $conflicted,
                'failed' => $failed,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 清理单条过期积分详情：详情置为已过期 -> 扣减会员积分 -> 写积分流水
     *
     * @return string expired=处理成功；conflict=版本号已被其他流程改动，本次跳过
     */
    private function clearExpiredDetail(MemberOrderDetail $detail): string
    {
        return Db::transaction(function () use ($detail) {
            $changePoints = number_format((float) $detail->change_points, 2, '.', '');
            $orderDetailsId = $detail->order_details_id;
            $detailVersion = $detail->version;
            $orderMasterId = $detail->order_master_id;
            $memberId = $detail->member_id;
            $orderNo = $detail->order_no;

            // 1. 详情表：激活(1) -> 已过期(3)，乐观锁防止重复处理
            $updatedDetail = MemberOrderDetail::query(true)
                ->where('order_details_id', $orderDetailsId)
                ->where('status', OrderCode::DETAIL_STATUS_ACTIVE)
                ->where('version', $detailVersion)
                ->update([
                    'status' => OrderCode::DETAIL_STATUS_EXPIRED,
                    'version' => $detailVersion + 1,
                ]);

            if ($updatedDetail === 0) {
                // 版本号或状态已变化（可能被其他流程处理过），跳过本条，不重复扣减
                $this->loggerService->warning('过期积分清理跳过：详情订单版本冲突', [
                    'order_details_id' => $orderDetailsId,
                    'order_master_id' => $orderMasterId,
                    'member_id' => $memberId,
                    'version' => $detailVersion,
                ]);

                return 'conflict';
            }

            // 2. 会员积分余额扣减：行锁读取，余额不足时扣到 0，不允许出现负数
            $member = Member::query()->select('member_id', 'points')->where('member_id', $memberId)->lockForUpdate()->first();
            if ($member === null) {
                throw new \RuntimeException('会员不存在 member_id=' . $memberId);
            }

            $beforePoints = number_format((float) $member->points, 2, '.', '');
            $actualDeduct = min((float) $beforePoints, (float) $changePoints);
            $actualDeduct = number_format($actualDeduct, 2, '.', '');
            $afterPoints = number_format((float) $beforePoints - (float) $actualDeduct, 2, '.', '');

            Member::query(true)
                ->where('member_id', $memberId)
                ->update([
                    'points' => Db::raw('GREATEST(points - ' . $actualDeduct . ', 0)'),
                    'version' => Db::raw('version + 1'),
                ]);

            // 3. 写积分流水（过期）
            MemberPointsLog::create([
                'member_id' => $memberId,
                'order_id' => $orderMasterId,
                'order_no' => $orderNo,
                'flag' => OrderCode::FLAG_DECREASE,
                'change_type' => OrderCode::CHANGE_TYPE_EXPIRE,
                'change_points' => $actualDeduct,
                'before_points' => $beforePoints,
                'after_points' => $afterPoints,
                'description' => sprintf(
                    '积分过期清理：订单%s 过期积分%s，实际扣减%s',
                    $orderNo,
                    $changePoints,
                    $actualDeduct
                ),
            ]);

            $this->loggerService->info('过期积分清理成功', [
                'order_details_id' => $orderDetailsId,
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'change_points' => $changePoints,
                'actual_deduct' => $actualDeduct,
                'before_points' => $beforePoints,
                'after_points' => $afterPoints,
            ]);

            return 'expired';
        });
    }
}
