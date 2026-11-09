<?php

declare(strict_types=1);

namespace App\Service\Api\Member;

use App\Constants\OrderCode;
use App\Exception\BusinessException;
use App\Model\Member;
use App\Model\MemberOrderDetail;
use App\Service\LoggerService;
use Hyperf\Di\Annotation\Inject;
use Throwable;

class MemberService
{
    #[Inject]
    protected LoggerService $loggerService;

    /**
     * 获取会员积分：总积分与即将过期积分
     * @param int $memberId
     * @return array
     */
    public function getPoints(int $memberId): array
    {
        try {
            $totalPoints = Member::query()
                ->where('member_id', $memberId)
                ->value('points');

            if ($totalPoints === null) {
                throw new BusinessException(400, '会员不存在');
            }

            $now = date('Y-m-d H:i:s');
            $in3Days = date('Y-m-d H:i:s', strtotime('+3 days'));
            $in7Days = date('Y-m-d H:i:s', strtotime('+7 days'));
            $in30Days = date('Y-m-d H:i:s', strtotime('+30 days'));

            $in3DaysTimestamp = strtotime($in3Days);
            $in7DaysTimestamp = strtotime($in7Days);

            $details = MemberOrderDetail::query()
                ->from('member_order_details as d')
                ->join('member_order_master as m', 'd.order_master_id', '=', 'm.order_master_id')
                ->whereNull('m.deleted_at')
                ->where('d.member_id', $memberId)
                ->where('d.status', OrderCode::DETAIL_STATUS_ACTIVE)
                ->whereNotNull('d.expiration_date')
                ->where('d.expiration_date', '>', $now)
                ->where('d.expiration_date', '<=', $in30Days)
                ->select('m.order_no', 'd.change_points', 'd.expiration_date')
                ->orderBy('d.expiration_date')
                ->get();

            // 以「分」为单位累加，避免浮点求和误差
            $buckets = [
                'within_3_days' => ['points_cents' => 0, 'orders' => []],
                'within_7_days' => ['points_cents' => 0, 'orders' => []],
                'within_30_days' => ['points_cents' => 0, 'orders' => []],
            ];

            foreach ($details as $detail) {
                $changePoints = (float) $detail->change_points;
                $pointsCents = (int) round($changePoints * 100);
                $expirationDate = $detail->expiration_date;

                $order = [
                    'order_no' => (string) $detail->order_no,
                    'change_points' => number_format($changePoints, 2, '.', ''),
                    'expiration_date' => $expirationDate->toDateTimeString(),
                ];

                // 按最窄的到期区间归入唯一一档：(now, +3] / (+3, +7] / (+7, +30]
                $expirationTimestamp = $expirationDate->getTimestamp();

                if ($expirationTimestamp <= $in3DaysTimestamp) {
                    $window = 'within_3_days';
                } elseif ($expirationTimestamp <= $in7DaysTimestamp) {
                    $window = 'within_7_days';
                } else {
                    $window = 'within_30_days';
                }

                $buckets[$window]['points_cents'] += $pointsCents;
                $buckets[$window]['orders'][] = $order;
            }

            $expiringPoints = [];
            foreach ($buckets as $window => $bucket) {
                $expiringPoints[$window] = [
                    'points' => number_format($bucket['points_cents'] / 100, 2, '.', ''),
                    'orders' => $bucket['orders'],
                ];
            }

            return [
                'code' => 200,
                'status' => 200,
                'data' => [
                    'total_points' => number_format((float) $totalPoints, 2, '.', ''),
                    'expiring_points' => $expiringPoints,
                ],
                'msg' => 'Success',
            ];
        } catch (BusinessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->loggerService->error('获取会员积分失败', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessException(500, '获取会员积分失败，请稍后重试');
        }
    }
}
