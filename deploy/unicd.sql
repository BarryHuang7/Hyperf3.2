SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for ju_admin_user
-- ----------------------------
DROP TABLE IF EXISTS `ju_admin_user`;
CREATE TABLE `ju_admin_user`  (
  `admin_id` bigint NOT NULL AUTO_INCREMENT COMMENT '管理员id',
  `email` varchar(255) NOT NULL COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `admin_name` varchar(65) NOT NULL COMMENT '昵称',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：默认1正常，0封禁',
  `description` varchar(255) NULL DEFAULT NULL COMMENT '描述',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`admin_id`),
  UNIQUE INDEX `email_index`(`email`) COMMENT '邮箱索引',
  INDEX `list_index`(`admin_id`, `created_at`) COMMENT '列表查询索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '后台用户表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for ju_member
-- ----------------------------
DROP TABLE IF EXISTS `ju_member`;
CREATE TABLE `ju_member`  (
  `member_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员id',
  `email` varchar(255) NOT NULL COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `member_name` varchar(65) NOT NULL COMMENT '昵称',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：默认1正常，0封禁',
  `points` decimal(12, 2) NOT NULL DEFAULT 0.00 COMMENT '积分余额，保量两位小数',
  `is_subscribe` tinyint NOT NULL DEFAULT 0 COMMENT '是否订阅会员：默认0否，1是',
  `google2fa_enabled` tinyint NOT NULL DEFAULT 0 COMMENT '谷歌2fa密钥开关：默认0关，1开',
  `google2fa_secret` varchar(512) NULL DEFAULT NULL COMMENT '谷歌2fa密钥',
  `automatically_create` tinyint NOT NULL DEFAULT 0 COMMENT '是否系统自动创建会员：默认0否，1是',
  `version` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '版本号，默认0',
  `description` varchar(255) COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL COMMENT '描述',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`member_id`),
  UNIQUE INDEX `email_index`(`email`) COMMENT '邮箱索引',
  INDEX `list_index`(`member_id`, `created_at`) COMMENT '列表查询索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for ju_member_order_details
-- ----------------------------
DROP TABLE IF EXISTS `ju_member_order_details`;
CREATE TABLE `ju_member_order_details`  (
  `order_details_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员积分订单详情id',
  `order_master_id` bigint NOT NULL COMMENT '会员积分订单主id',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '积分状态：0未激活，1激活，2已消耗完，3已过期',
  `change_points` decimal(12, 2) NOT NULL COMMENT '变动积分',
  `expiration_date` datetime NULL DEFAULT NULL COMMENT '过期时间',
  `version` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '版本号，默认0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`order_details_id`),
  INDEX `order_master_id_index`(`order_master_id`) COMMENT '积分订单主表id索引',
  INDEX `select_expiration_index`(`status`, `expiration_date`) COMMENT '查询过期索引',
  INDEX `fifo_select_index`(`member_id`, `status`, `expiration_date`) COMMENT '新进新出查询索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分订单详情表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for ju_member_order_master
-- ----------------------------
DROP TABLE IF EXISTS `ju_member_order_master`;
CREATE TABLE `ju_member_order_master`  (
  `order_master_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员积分订单主id',
  `order_no` varchar(64) NOT NULL COMMENT '积分订单编号',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `payment_status` tinyint NOT NULL COMMENT '订单支付状态：0无需支付，1待支付，2已支付完成，3支付失败，4支付超时',
  `stripe_checkout_id` varchar(255) NULL DEFAULT NULL COMMENT 'stripe会话id',
  `stripe_checkout_url` varchar(2048) NULL DEFAULT NULL COMMENT 'stripe会话地址',
  `payment_intent_id` varchar(255) NULL DEFAULT NULL COMMENT 'stripe支付id',
  `flag` tinyint NOT NULL COMMENT '新增或者减少：0为减少，1为新增',
  `change_type` tinyint NOT NULL COMMENT '积分流水类型：1-RECHARGE充值，4-ADMIN_ADJUST管理员调整',
  `change_points` decimal(12, 2) NOT NULL COMMENT '变动积分（恒为正数，方向由flag决定）',
  `version` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '版本号，默认0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`order_master_id`),
  UNIQUE INDEX `order_no_index`(`order_no`) COMMENT '订单编号索引',
  INDEX `list_index`(`member_id`, `created_at` DESC) COMMENT '订单列表索引',
  INDEX `list_filtrate_index`(`member_id`, `change_type`, `created_at` DESC) COMMENT '订单列表筛选类型索引',
  UNIQUE INDEX `stripe_checkout_id_index`(`stripe_checkout_id`) COMMENT 'stripe会话id索引',
  UNIQUE INDEX `payment_intent_id_index`(`payment_intent_id`) COMMENT 'stripe支付id索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分订单主表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for ju_member_points_log
-- ----------------------------
DROP TABLE IF EXISTS `ju_member_points_log`;
CREATE TABLE `ju_member_points_log`  (
  `points_log_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员积分流水id',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `order_id` bigint NOT NULL COMMENT '积分订单id',
  `order_no` varchar(64) NOT NULL COMMENT '积分订单编号',
  `points_task_id` bigint NULL COMMENT '会员积分任务id',
  `points_task_no` varchar(64) NOT NULL COMMENT '会员积分任务编号',
  `flag` tinyint NOT NULL COMMENT '新增或者减少：0为减少，1为新增',
  `change_type` tinyint NOT NULL COMMENT '积分流水类型：1-RECHARGE充值，2-CONSUME消费，3-EXPIRE过期，4-ADMIN_ADJUST管理员调整，5-GIFT本地模式注册赠送，6-REFUND积分退还',
  `change_points` decimal(12, 2) NOT NULL COMMENT '变动积分（恒为正数，方向由flag决定）',
  `before_points` decimal(12, 2) NOT NULL COMMENT '变动前积分',
  `after_points` decimal(12, 2) NOT NULL COMMENT '变动后积分',
  `video_width` smallint UNSIGNED NULL DEFAULT NULL COMMENT '视频分辨率-宽度',
  `video_height` smallint UNSIGNED NULL DEFAULT NULL COMMENT '视频分辨率-高度',
  `video_frame_rate` decimal(10, 2) NULL DEFAULT NULL COMMENT '视频帧率',
  `video_feature_type` tinyint NULL DEFAULT NULL COMMENT '视频处理的类型：1-Subtitle字母，2-Upscaler放大器，3-HDR高动态范围，4-Colorizer着色器，5-Translator语言翻译',
  `video_duration_seconds` decimal(12, 4) NULL DEFAULT NULL COMMENT '视频处理的时长（秒）',
  `charging_standard` decimal(10, 2) NULL DEFAULT NULL COMMENT '扣费标准（积分/分钟）',
  `description` varchar(255) NOT NULL COMMENT '描述：本条记录的基本信息',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`points_log_id`),
  INDEX `member_id_index`(`member_id`, `created_at`) COMMENT '会员id索引',
  INDEX `order_no_index`(`order_no`) COMMENT '积分订单编号索引',
  INDEX `points_task_id_index`(`points_task_id`) COMMENT '会员积分任务id索引',
  INDEX `points_statistics_index`(`change_type`, `flag`, `change_points`, `created_at`) COMMENT '会员积分统计索引',
  INDEX `points_task_no_index`(`points_task_no`, `change_type`) COMMENT '返还积分幂等检查索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for ju_stripe_json
-- ----------------------------
DROP TABLE IF EXISTS `ju_stripe_json`;
CREATE TABLE `ju_stripe_json`  (
  `stripe_json_id` bigint NOT NULL AUTO_INCREMENT COMMENT 'stripe返回的json表id',
  `order_no` varchar(64) NOT NULL COMMENT '会员积分订单编号',
  `checkout_session_json` json NULL DEFAULT NULL COMMENT '会话返回json',
  `payment_intent_json` json NULL DEFAULT NULL COMMENT '回调接口支付成功返回json',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`stripe_json_id`),
  UNIQUE INDEX `order_no_index`(`order_no`) COMMENT '会员积分订单编号索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = 'stripe返回的json数据记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Table structure for ju_plan_pricing
-- ----------------------------
DROP TABLE IF EXISTS `ju_plan_pricing`;
CREATE TABLE `ju_plan_pricing`  (
  `plan_id` int NOT NULL AUTO_INCREMENT COMMENT '套餐定价id',
  `plan_name` varchar(64) NOT NULL COMMENT '套餐名称',
  `points` decimal(12, 2) NOT NULL COMMENT '积分，保量两位小数',
  `price` decimal(12, 2) NOT NULL COMMENT '售价（USD）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`plan_id`)
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '套餐定价表' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of ju_plan_pricing
-- ----------------------------
INSERT INTO `ju_plan_pricing` VALUES (1, 'Basic Pack', 600.00, 39.99, '2026-10-26 16:18:26', '2026-10-26 16:18:26', NULL);
INSERT INTO `ju_plan_pricing` VALUES (2, 'Pro Pack', 1000.00, 59.99, '2026-10-26 16:18:43', '2026-10-26 16:18:43', NULL);

-- ----------------------------
-- Table structure for ju_video_billing_module
-- ----------------------------
DROP TABLE IF EXISTS `ju_video_billing_module`;
CREATE TABLE `ju_video_billing_module`  (
  `moudle_id` int NOT NULL AUTO_INCREMENT COMMENT '视频计费模块id',
  `video_feature_type` tinyint NULL DEFAULT NULL COMMENT '视频处理的类型：1-Subtitle字母，2-Upscaler放大器，3-HDR高动态范围，4-Colorizer着色器，5-Translator语言翻译',
  `moudle_name` varchar(255) NOT NULL COMMENT '模块名称',
  `moudle_specifications` varchar(255) NOT NULL COMMENT '模块规格',
  `charging_standard` decimal(10, 2) NOT NULL COMMENT '扣费标准（积分/分钟）',
  `minimum_guaranteed_deduction_fee` decimal(10, 2) NOT NULL DEFAULT 0.5 COMMENT '最低兜底扣费（固定0.5）',
  `hd` decimal(10, 2) NULL DEFAULT NULL COMMENT '标清/高清（1080p及以下）宽1280且高720 或 宽1920且高1080',
  `2k` decimal(10, 2) NULL DEFAULT NULL COMMENT '2K极清（1440p）1080p<宽高<2560',
  `4k` decimal(10, 2) NULL DEFAULT NULL COMMENT '4k超清（2160p）宽高>=2560',
  `fps30` decimal(10, 2) NULL DEFAULT NULL COMMENT '常规帧率（FPS30帧）',
  `fps45` decimal(10, 2) NULL DEFAULT NULL COMMENT '中帧率（FPS45帧）',
  `fps60` decimal(10, 2) NULL DEFAULT NULL COMMENT '高帧率（FPS60帧）',
  `above_fps60` decimal(10, 2) NULL DEFAULT NULL COMMENT '高帧率（FPS60帧以上）',
  `fps90` decimal(10, 2) NULL DEFAULT NULL COMMENT '超高帧率（FPS90帧）',
  `above_fps90` decimal(10, 2) NULL DEFAULT NULL COMMENT '极高帧率（FPS90帧以上）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`moudle_id`),
  INDEX `video_feature_type_index`(`video_feature_type`) COMMENT '视频处理的类型索引'
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '视频计费模块表';

-- ----------------------------
-- Records of ju_video_billing_module
-- ----------------------------
INSERT INTO `ju_video_billing_module` VALUES (1, 1, 'Subtitle Generator AI', '云端全自动模式（无缝支持99+语种，免手动设置）', 0.80, 0.50, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-27 14:24:22', '2026-10-27 14:24:22', NULL);
INSERT INTO `ju_video_billing_module` VALUES (2, 2, 'Video Upscaler AI', '基础起扣', 0.60, 0.50, 1.00, 2.00, 4.00, 1.00, NULL, 1.50, 2.00, NULL, NULL, '2026-10-27 14:30:17', '2026-10-27 14:33:08', NULL);
INSERT INTO `ju_video_billing_module` VALUES (3, 3, 'HDR Upconverter AI', '基础起扣', 1.00, 0.50, 1.00, 2.00, 4.00, 1.00, NULL, 1.50, 2.00, NULL, NULL, '2026-10-27 14:36:43', '2026-10-27 14:38:45', NULL);
INSERT INTO `ju_video_billing_module` VALUES (4, 4, 'Video Colorizer AI', '基础起扣', 3.00, 0.50, NULL, NULL, NULL, 3.00, 4.50, 6.00, NULL, 9.00, 12.00, '2026-10-27 14:38:42', '2026-10-27 15:55:52', NULL);
INSERT INTO `ju_video_billing_module` VALUES (5, 5, 'Video Translator AI', '云端全自动模式（翻译+AI配音/音色克隆）', 1.00, 0.50, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-27 14:41:24', '2026-10-27 15:55:54', NULL);

-- ----------------------------
-- Table structure for ju_member_points_task
-- ----------------------------
DROP TABLE IF EXISTS `ju_member_points_task`;
CREATE TABLE `ju_member_points_task`  (
  `points_task_id` bigint NOT NULL AUTO_INCREMENT COMMENT '会员积分任务id',
  `points_task_no` varchar(64) NOT NULL COMMENT '会员积分任务编号',
  `member_id` bigint NOT NULL COMMENT '会员id',
  `video_feature_type` tinyint NOT NULL COMMENT '视频处理的类型：1-Subtitle字母，2-Upscaler放大器，3-HDR高动态范围，4-Colorizer着色器，5-Translator语言翻译',
  `video_width` smallint(5) UNSIGNED NOT NULL COMMENT '视频分辨率-宽度',
  `video_height` smallint(8) UNSIGNED NOT NULL COMMENT '视频分辨率-高度',
  `video_frame_rate` decimal(10, 2) NOT NULL COMMENT '视频帧率',
  `video_duration_seconds` decimal(12, 4) NOT NULL COMMENT '视频处理的时长（秒）',
  `charging_standard` decimal(10, 2) NOT NULL COMMENT '扣费标准（积分/分钟）',
  `deduct_points` decimal(12, 2) NOT NULL COMMENT '扣除的积分',
  `platform_id` varchar(255) NULL DEFAULT NULL COMMENT 'AI平台的任务id',
  `platform_type` tinyint NULL DEFAULT 0 COMMENT 'AI平台的类型：0-默认，1-assemblyai，2-wavespeed，3-火山引擎，4-腾讯云，5-，6-',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '任务状态：0-默认，1-成功，2-进行中，3-未开始，4-失败，5-用户取消',
  `version` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '版本号，默认0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`points_task_id`),
  INDEX `list_index`(`member_id`, `created_at` DESC) COMMENT '列表索引',
  INDEX `list_filtrate_index`(`member_id`, `video_feature_type`, `status`, `created_at` DESC) COMMENT '列表筛选类型索引',
  UNIQUE INDEX `points_task_no_index`(`points_task_no`) COMMENT '会员积分任务编号索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分任务表';

-- ----------------------------
-- Table structure for ju_member_points_task_order
-- ----------------------------
DROP TABLE IF EXISTS `ju_member_points_task_order`;
CREATE TABLE `ju_member_points_task_order`  (
  `points_task_order_id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT '会员积分任务订单id',
  `points_task_id` bigint(20) NOT NULL COMMENT '会员积分任务id',
  `order_master_id` bigint(20) NOT NULL COMMENT '会员积分订单主id',
  `order_no` varchar(64) NOT NULL COMMENT '积分订单编号',
  `order_details_id` bigint(20) NOT NULL COMMENT '会员积分订单详情id',
  `deduct_points` decimal(12, 2) NOT NULL COMMENT '扣除的积分',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`points_task_order_id`),
  INDEX `join_index`(`points_task_id`, `order_no`) COMMENT '关联索引',
  INDEX `order_no_index`(`order_no`) COMMENT '积分订单编号索引'
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci COMMENT = '会员积分任务使用的订单记录表';

SET FOREIGN_KEY_CHECKS = 1;
