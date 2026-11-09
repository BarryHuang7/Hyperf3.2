-- 增加审计备注字段 2026-11-5 09:38:25
ALTER TABLE `ju_member_points_log` 
ADD COLUMN `audit_notes` varchar(255) NULL COMMENT '审计备注' AFTER `description`;

-- 增加枚举 7-订单退款 2026-11-5 10:14:16
ALTER TABLE `ju_member_points_log` 
MODIFY COLUMN `change_type` tinyint NOT NULL COMMENT '积分流水类型：1-RECHARGE充值，2-CONSUME消费，3-EXPIRE过期，4-ADMIN_ADJUST管理员调整，5-GIFT本地模式注册赠送，6-REFUND积分退还，7-ORDER_REFUND订单退款' AFTER `flag`;
ALTER TABLE `ju_member_order_master` 
MODIFY COLUMN `change_type` tinyint NOT NULL COMMENT '积分流水类型：1-RECHARGE充值，4-ADMIN_ADJUST管理员调整，7-ORDER_REFUND订单退款' AFTER `flag`;
-- 增加stripe退款表
DROP TABLE IF EXISTS `ju_member_order_refund`;
CREATE TABLE `ju_member_order_refund`  (
  `refund_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员积分订单退款id',
  `order_master_id` bigint NOT NULL COMMENT '会员积分订单主id',
  `order_no` varchar(64) NOT NULL COMMENT '积分订单编号',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '退款状态：默认0，1-退款成功，2-退款申请通过，3-退款申请拒绝，4-申请退款，5-退款失败',
  `price` decimal(12, 2) NOT NULL COMMENT '退款金额（USD）',
  `stripe_refund_id` varchar(255) NULL COMMENT 'stripe退款id',
  `reason_for_application` varchar(255) NOT NULL COMMENT '申请原因',
  `description` varchar(255) NULL COMMENT '退款描述（客服填写）',
  `version` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '版本号，默认0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`refund_id`),
  INDEX `select_index`(`order_no`, `member_id`, `status`) COMMENT '查询个人退款记录索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分订单退款表';
-- 增加支付完成时间字段
ALTER TABLE `ju_member_order_master` 
ADD COLUMN `paid_at` datetime NULL COMMENT 'stripe支付完成时间' AFTER `payment_intent_id`;
-- 增加本地注册码赠送积分对照配置表
DROP TABLE IF EXISTS `ju_registration_code_config`;
CREATE TABLE `ju_registration_code_config`  (
  `package_id` int NOT NULL AUTO_INCREMENT COMMENT '套餐id',
  `package_name` varchar(100) NOT NULL COMMENT '套餐名称',
  `package_explain` varchar(100) NOT NULL COMMENT '套餐说明',
  `one_year` decimal(12, 2) NOT NULL COMMENT '一年期赠送积分',
  `lifetime` decimal(12, 2) NOT NULL COMMENT '永久期赠送积分',
  `remark` varchar(255) NOT NULL COMMENT '备注说明',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`package_id`)
) ENGINE = InnoDB AUTO_INCREMENT = 18 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '本地注册码对应套餐赠送积分配置表';
-- ----------------------------
-- Records of ju_registration_code_config
-- ----------------------------
INSERT INTO `ju_registration_code_config` VALUES (1, 'AuraGet V1 大包', '大包全能版', 0.00, 3000.00, '旗舰全能包，算力成本仅占售价的 3.4%~4.3%', '2026-11-05 15:42:45', '2026-11-05 15:56:30', NULL);
INSERT INTO `ju_registration_code_config` VALUES (2, 'Video Upscaler AI', '高算力 AI 模块', 1000.00, 1500.00, '核心超分画质提升', '2026-11-05 15:43:06', '2026-11-05 15:56:35', NULL);
INSERT INTO `ju_registration_code_config` VALUES (3, 'HDR Upconverter AI', '高算力 AI 模块', 1000.00, 1500.00, 'HDR 动态画质重建', '2026-11-05 15:43:26', '2026-11-05 15:56:39', NULL);
INSERT INTO `ju_registration_code_config` VALUES (4, 'Video Colorizer AI', '高算力 AI 模块', 1000.00, 1500.00, '黑白视频/老片上色', '2026-11-05 15:43:46', '2026-11-05 15:56:44', NULL);
INSERT INTO `ju_registration_code_config` VALUES (5, 'Face Enhancer AI', '高算力 AI 模块', 1000.00, 1500.00, '人像高精高清修复', '2026-11-05 15:44:08', '2026-11-05 15:56:48', NULL);
INSERT INTO `ju_registration_code_config` VALUES (6, 'Subtitle Generator AI', '高频云端 AI 模块', 1000.00, 1500.00, '云端语音识别与字幕生成', '2026-11-05 15:48:08', '2026-11-05 15:56:52', NULL);
INSERT INTO `ju_registration_code_config` VALUES (7, 'RTX Rapid Upscaler AI', 'RTX 硬件加速', 800.00, 1200.00, '显卡硬件加速超分', '2026-11-05 15:48:35', '2026-11-05 15:56:58', NULL);
INSERT INTO `ju_registration_code_config` VALUES (8, 'RTX RapidHDR AI', 'RTX 硬件加速', 800.00, 1200.00, '显卡硬件加速 HDR', '2026-11-05 15:52:20', '2026-11-05 15:57:02', NULL);
INSERT INTO `ju_registration_code_config` VALUES (9, 'Denoise AI', '中高算力 AI 模块', 800.00, 1200.00, '视频降噪处理', '2026-11-05 15:52:36', '2026-11-05 15:57:06', NULL);
INSERT INTO `ju_registration_code_config` VALUES (10, 'RTX Rapid Denoiser AI', 'RTX 硬件加速', 800.00, 1200.00, '显卡硬件加速降噪', '2026-11-05 15:52:58', '2026-11-05 15:57:10', NULL);
INSERT INTO `ju_registration_code_config` VALUES (11, 'Audio Upmix AI', '音频 AI 模块', 600.00, 1000.00, '音频重采样与环绕声', '2026-11-05 15:53:15', '2026-11-05 15:57:14', NULL);
INSERT INTO `ju_registration_code_config` VALUES (12, 'Smoother AI', '轻量/中度 AI 模块', 600.00, 1000.00, 'AI 视频补帧', '2026-11-05 15:53:34', '2026-11-05 15:57:19', NULL);
INSERT INTO `ju_registration_code_config` VALUES (13, 'Deinterlace AI', '轻量 AI 模块', 600.00, 1000.00, '隔行扫描消除', '2026-11-05 15:53:53', '2026-11-05 15:57:23', NULL);
INSERT INTO `ju_registration_code_config` VALUES (14, 'Vocal Remover AI', '免费基础工具', 0.00, 0.00, '免费引流工具', '2026-11-05 15:54:12', '2026-11-05 15:57:30', NULL);
INSERT INTO `ju_registration_code_config` VALUES (15, 'Video BG Remover AI', '免费基础工具', 0.00, 0.00, '免费引流工具', '2026-11-05 15:54:28', '2026-11-05 15:57:30', NULL);
INSERT INTO `ju_registration_code_config` VALUES (16, 'Video Converter', '免费传统工具', 0.00, 0.00, '基础格式转换', '2026-11-05 15:54:41', '2026-11-05 15:57:34', NULL);
INSERT INTO `ju_registration_code_config` VALUES (17, 'Compressor', '免费传统工具', 0.00, 0.00, '基础视频压缩', '2026-11-05 15:54:56', '2026-11-05 15:57:38', NULL);
-- 修改points_task_no为非必填
ALTER TABLE `ju_member_points_log` 
MODIFY COLUMN `points_task_no` varchar(64) NULL COMMENT '会员积分任务编号' AFTER `points_task_id`;
-- 增加price字段
ALTER TABLE `ju_member_order_master` 
ADD COLUMN `price` decimal(12, 2) NOT NULL COMMENT '支付金额（USD）' AFTER `change_points`;

-- 增加枚举 5-GIFT本地模式注册赠送 2026-11-6 09:31:19
ALTER TABLE `ju_member_order_master` 
MODIFY COLUMN `change_type` tinyint(4) NOT NULL COMMENT '积分流水类型：1-RECHARGE充值，4-ADMIN_ADJUST管理员调整，5-GIFT本地模式注册赠送，7-ORDER_REFUND订单退款' AFTER `flag`;
-- 增加本地注册码兑换记录表
CREATE TABLE `ju_registration_code_log`  (
  `registration_code_log_id` bigint NOT NULL AUTO_INCREMENT COMMENT '本地注册码激活记录id',
  `registration_code` varchar(255) NOT NULL COMMENT '本地注册码',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `order_master_id` bigint NOT NULL COMMENT '会员积分订单主id',
  `order_no` varchar(64) NOT NULL COMMENT '积分订单编号',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`registration_code_log_id`),
  INDEX `registration_code_index`(`registration_code`) COMMENT '本地注册码索引',
  INDEX `member_id_index`(`member_id`) COMMENT '会员id索引',
  INDEX `order_no`(`order_no`) COMMENT '积分订单编号索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '本地注册码激活记录表';
