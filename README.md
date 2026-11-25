# Hyperf3.2
基于PHP8.5.12开发的Hyperf3.2框架，集成了鉴权、验证器、拦截器、Stripe、WebSocket、WebHook、Google2FA、日志跟踪等。

已下文档由本地模型gpt-oss:20B生成

## Detailed API Reference

### /api

#### POST /api/register

**Description**: 注册

**Request Headers**: None

**Request Body**:
```json
{
  "email": "string",
  "password": "string",
  "member_name": "string",
  "verification_code": 123456
}
```
- `email`: required, string, email format, max 255
- `password`: required, string, min 6, max 64
- `member_name`: required, string, max 65
- `verification_code`: required, integer, 6 digits

**Response**:
```json
{
  "code": 200,
  "data": {
    "member_id": 123,
    "token": "jwt_token",
    "member_name": "John Doe"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/login

**Description**: 登录

**Request Headers**:
- `VerifyCode` (optional): Verification code UUID used by the service.

**Request Body**:
```json
{
  "email": "string",
  "password": "string",
  "verify_code": "string",
  "temp_token": "string (optional)",
  "2fa_code": "string (optional)"
}
```
- `email`: required, string, email format, max 255
- `password`: required, string
- `verify_code`: required, string, max 6
- `temp_token`: optional, string
- `2fa_code`: optional, string, max 6

**Response**:
```json
{
  "code": 200,
  "data": {
    "member_id": 123,
    "token": "jwt_token",
    "refresh_token": "refresh_token"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### GET /api/verification-code

**Description**: 获取验证码

**Request Headers**:
- `VerifyCode` (optional): Header used by the service.

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": {
    "verification_code": "string",
    "expires_in": 300
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/logout

**Description**: 登出

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": {
    "message": "Logged out successfully"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/refresh-token

**Description**: 刷新 Token

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": {
    "token": "new_jwt_token",
    "refresh_token": "new_refresh_token"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/send-email-verification-code

**Description**: 发送注册邮箱验证码

**Request Headers**: None

**Request Body**:
```json
{
  "email": "string (optional)"
}
```
- `email`: optional, string, email format, max 255

**Response**:
```json
{
  "code": 200,
  "data": {
    "message": "Email verification code sent"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/enable-google2fa

**Description**: 开启 Google 2FA

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": {
    "secret": "BASE32-ENCODED-SECRET",
    "qr_code_url": "https://..."
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/confirm-google2fa

**Description**: 绑定 Google 2FA

**Request Headers**: None

**Request Body**:
```json
{
  "code": 123456
}
```
- `code`: required, integer, 6 digits

**Response**:
```json
{
  "code": 200,
  "data": {
    "message": "Google 2FA enabled"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/reset-password

**Description**: 重置密码

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "old_password": "string",
  "new_password": "string"
}
```
- `old_password`: required, string
- `new_password`: required, string, min 6, max 64

**Response**:
```json
{
  "code": 200,
  "data": {
    "message": "Password updated"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/create-checkout-session

**Description**: 创建 Stripe 结账会话

**Request Headers**: None

**Request Body**:
```json
{
  "plan_id": 1,
  "email": "string (optional)"
}
```
- `plan_id`: required, integer
- `email`: optional, string, email format, max 255

**Response**:
```json
{
  "code": 200,
  "data": {
    "stripe_checkout_id": "cs_test_123",
    "url": "https://checkout.stripe.com/pay/cs_test_123",
    "order_no": "ORD20261125-001"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/stripe/webhook

**Description**: Stripe 支付回调

**Request Headers**:
- `Stripe-Signature`: required, Stripe signing secret header.

**Request Body**: Stripe event JSON, e.g.:
```json
{
  "id": "evt_1HXXXX",
  "object": "event",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_1HXXXX",
      "metadata": {
        "order_no": "ORD20261125-001"
      }
    }
  }
}
```

**Response**: `200 OK` plain text `OK`.

#### GET /api/get-plan-list

**Description**: 获取套餐列表

**Request Headers**: None

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": [
    {
      "plan_id": 1,
      "name": "Basic",
      "price": 9.99,
      "features": ["feature1", "feature2"]
    }
  ],
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/get-order-list

**Description**: 获取积分订单充值列表

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "page": 1,
  "page_size": 10,
  "change_type": 1
}
```
- `page`: required, integer, min 1
- `page_size`: required, integer, min 1, max 50
- `change_type`: optional, integer, 1 or 2

**Response**:
```json
{
  "code": 200,
  "data": {
    "orders": [
      {
        "order_no": "ORD20261125-001",
        "points": 100,
        "change_type": 1,
        "created_at": "2026-11-25T12:00:00Z"
      }
    ],
    "page": 1,
    "page_size": 10,
    "total": 1
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### GET /api/get-order-detail

**Description**: 获取积分订单详情

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Query Parameters**:
- `order_no` (required): string

**Response**:
```json
{
  "code": 200,
  "data": {
    "order_no": "ORD20261125-001",
    "points": 100,
    "change_type": 1,
    "details": [
      {"item": "Premium Video", "points": 100}
    ],
    "created_at": "2026-11-25T12:00:00Z"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/consumption-points

**Description**: 消耗积分（视频处理等）

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "feature_type": 1,
  "duration_seconds": 120.5,
  "width": 1920,
  "height": 1080,
  "fps": 30.0
}
```
- `feature_type`: required, integer, 1-5 (see FeatureType enum)
- `duration_seconds`: required, numeric > 0
- `width`: required, integer > 0
- `height`: required, integer > 0
- `fps`: required, numeric > 0

**Response**:
```json
{
  "code": 200,
  "data": {
    "points_used": 50,
    "remaining_points": 150
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/get-points-task-list

**Description**: 获取积分消耗任务记录列表

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "page": 1,
  "page_size": 10,
  "video_feature_type": 1,
  "status": 2
}
```
- `page`: required, integer, min 1
- `page_size`: required, integer, min 1, max 50
- `video_feature_type`: optional, integer, 1-5
- `status`: optional, integer, 0-5

**Response**:
```json
{
  "code": 200,
  "data": {
    "tasks": [
      {
        "points_task_no": "TASK20261125-001",
        "feature_type": 1,
        "status": 2,
        "created_at": "2026-11-25T12:00:00Z"
      }
    ],
    "page": 1,
    "page_size": 10,
    "total": 1
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### GET /api/get-points-task-order

**Description**: 获取积分消耗任务对应订单记录

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Query Parameters**:
- `points_task_id` (required): integer

**Response**:
```json
{
  "code": 200,
  "data": {
    "order_no": "ORD20261125-001",
    "points": 100
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### GET /api/get-member-points

**Description**: 获取会员积分和即将过期积分

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Response**:
```json
{
  "code": 200,
  "data": {
    "points": 200,
    "expiring_points": 50,
    "expiry_date": "2026-12-31"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/verify-registration-code

**Description**: 校验本地模式注册码

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "registration_code": "ABCDEF123456"
}
```
- `registration_code`: required, string, max 191

**Response**:
```json
{
  "code": 200,
  "data": {
    "valid": true,
    "message": "Registration code verified"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### PUT /api/update-points-task

**Description**: 更新积分任务平台信息

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**:
```json
{
  "points_task_no": "TASK20261125-001",
  "platform_id": "PLATFORM123",
  "platform_type": 2
}
```
- `points_task_no`: required, string, max 64
- `platform_id`: required, string, max 255
- `platform_type`: required, integer, 1-6 (enumeration of supported platforms)

**Response**:
```json
{
  "code": 200,
  "data": {
    "message": "Points task updated"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /api/assemblyai/webhook

**Description**: AssemblyAI 任务回调

**Request Headers**:
- `Authorization`: `Bearer <token>`

**Query Parameters**:
- `points_task_no` (required): string

**Request Body** (JSON):
```json
{
  "status": "completed",
  "transcript_id": "TRANSCRIPT123"
}
```
- `status`: required, string (e.g., `completed`, `processing`, `failed`)
- `transcript_id`: required, string

**Response**: `200 OK` plain text `OK`.

#### POST /api/wavespeedai/webhook

**Description**: WaveSpeedAI 任务回调

**Request Headers**:
- `webhook-id`: required, string
- `webhook-timestamp`: required, integer (Unix epoch)
- `webhook-signature`: required, string (HMAC SHA256)

**Query Parameters**:
- `points_task_no` (required): string

**Request Body** (JSON):
```json
{
  "status": "completed"
}
```
- `status`: required, string

**Response**: `200 OK` plain text `OK`.

#### POST /api/tencentcloudci/webhook

**Description**: TencentCloudCI 任务回调

**Request Headers**: None

**Request Body** (JSON):
```json
{
  "JobsDetail": [
    {
      "JobId": "JOB123",
      "State": "SUCCESS",
      "Operation": {
        "UserData": "BASE64_ENCODED_JSON"
      }
    }
  ]
}
```
The `UserData` field is a base64‑encoded JSON string containing `token` and `points_task_no`.

**Response**: `200 OK` plain text `OK`.

#### POST /api/volcengineai/webhook

**Description**: VolcengineAI MediaKit 任务回调

**Request Headers**: None

**Request Body** (JSON):
```json
{
  "TaskId": "TASK123",
  "Data": {
    "Code": 0,
    "CallbackArgs": "BASE64_ENCODED_JSON"
  }
}
```
The `CallbackArgs` field is a base64‑encoded JSON string containing `token` and `points_task_no`.

**Response**: `200 OK` plain text `OK`.

### /admin

#### POST /admin/register

**Description**: 注册

**Request Headers**: None

**Request Body**:
```json
{
  "email": "string",
  "password": "string",
  "admin_name": "string"
}
```
- `email`: required, string, email format, max 255, unique
- `password`: required, string, min 6, max 64
- `admin_name`: required, string, max 65

**Response**:
```json
{
  "code": 200,
  "data": {
    "admin_id": 1,
    "token": "jwt_token"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/login

**Description**: 登录

**Request Headers**:
- `VerifyCode`: optional header

**Request Body**:
```json
{
  "email": "string",
  "password": "string",
  "verify_code": "string"
}
```
- `email`: required, string, email format, max 255
- `password`: required, string
- `verify_code`: required, string

**Response**:
```json
{
  "code": 200,
  "data": {
    "admin_id": 1,
    "token": "jwt_token",
    "refresh_token": "refresh_token"
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/logout

**Description**: 登出

**Request Headers**:
- `Authorization`: `Bearer <jwt_token>`

**Request Body**: None

**Response**:
```json
{
  "code": 200,
  "data": {"message": "Logged out"},
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/get-member-points-list

**Description**: 会员积分管理列表

**Request Headers**:
- `Authorization`: `Bearer <admin_token>`

**Request Body**:
```json
{
  "page": 1,
  "page_size": 10,
  "email": "string (optional)",
  "member_id": 123 (optional)
}
```
- `page`: required, integer, min 1
- `page_size`: required, integer, min 1, max 50
- `email`: optional, string, email format, max 255
- `member_id`: optional, integer, min 1

**Response**:
```json
{
  "code": 200,
  "data": {
    "members": [
      {
        "member_id": 123,
        "email": "user@example.com",
        "points": 200
      }
    ],
    "page": 1,
    "page_size": 10,
    "total": 1
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/get-member-points-detail

**Description**: 会员积分详情

**Request Headers**:
- `Authorization`: `Bearer <admin_token>`

**Request Body**:
```json
{
  "member_id": 123,
  "page": 1,
  "page_size": 10
}
```
- `member_id`: required, integer, min 1
- `page`: required, integer, min 1
- `page_size`: required, integer, min 1, max 50

**Response**:
```json
{
  "code": 200,
  "data": {
    "member_id": 123,
    "points_history": [
      {
        "change_points": 50,
        "type": "recharge",
        "created_at": "2026-11-25T12:00:00Z"
      }
    ]
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/get-member-points-log-list

**Description**: 会员积分流水

**Request Headers**:
- `Authorization`: `Bearer <admin_token>`

**Request Body**:
```json
{
  "member_id": 123,
  "page": 1,
  "page_size": 10
}
```
- `member_id`: required, integer, min 1
- `page`: required, integer, min 1
- `page_size`: required, integer, min 1, max 50

**Response**:
```json
{
  "code": 200,
  "data": {
    "logs": [
      {
        "log_id": 1,
        "change_points": 50,
        "type": "recharge",
        "created_at": "2026-11-25T12:00:00Z"
      }
    ],
    "page": 1,
    "page_size": 10,
    "total": 1
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

#### POST /admin/adjust-member-points

**Description**: 会员积分增减

**Request Headers**:
- `Authorization`: `Bearer <admin_token>`

**Request Body**:
```json
{
  "member_id": 123,
  "flag": 1,
  "change_points": 100.50,
  "remark": "Bonus points",
  "audit_notes": "Approved by admin",
  "expiration_date": "2027-01-01"
}
```
- `member_id`: required, integer, min 1
- `flag`: required, integer, 0 (decrease) or 1 (increase)
- `change_points`: required, numeric, min 0.01, max 9999999999.99
- `remark`: required, string, max 200
- `audit_notes`: required, string, max 200
- `expiration_date`: required if `flag` is 1, string in `YYYY-MM-DD` format

**Response**:
```json
{
  "code": 200,
  "data": {"message": "Points adjusted"},
  "msg": "",
  "error": "",
  "status": 200
}
```

#### GET /admin/get-member-points-statistics

**Description**: 积分消耗统计

**Request Headers**:
- `Authorization`: `Bearer <admin_token>`

**Response**:
```json
{
  "code": 200,
  "data": {
    "total_points": 5000,
    "used_points": 1200,
    "remaining_points": 3800
  },
  "msg": "",
  "error": "",
  "status": 200
}
```

### /ws

#### GET /websocket

**Description**: WebSocket endpoint

**Request Headers**: None

**Response**: WebSocket handshake (not shown in JSON)

### 兜底接口说明

- **WebSocket 消息**
  ```json
  {
    "path": "checkOrderStatus|getTaskStatus",
    "params": {
      "order_no": "ORD123",          // checkOrderStatus 时必填
      "points_task_no": "TASK456"    // getTaskStatus 时必填
    }
  }
  ```
  服务器根据 `path` 处理并以 JSON 通过同一 WebSocket 连接 `push` 结果。示例响应（`checkOrderStatus`）:
  ```json
  {
    "code": 200,
    "data": {
      "order_no": "ORD123",
      "payment_status": 1,
      "change_points": "100.00",
      "stripe_checkout_url": "https://checkout.stripe.com/pay/...",
      "created_at": "2026-11-25T12:00:00Z"
    },
    "msg": "Success"
  }
  ```
  此接口可在后端或前端出现异常时手动查询订单/任务状态。

### Notes

All API responses follow the JSON structure:
```json
{
  "code": 200,
  "data": {...},
  "msg": "",
  "error": "",
  "status": 200
}
```
- `code`: application‑level status code (200 for success, other values indicate errors)
- `data`: payload specific to the endpoint
- `msg`: human‑readable message
- `error`: error description if any
- `status`: HTTP status code

**Common Request Headers**
- `Content-Type: application/json` for all POST/PUT requests with a body.
- `Authorization: Bearer <token>` for authenticated endpoints.
- `VerifyCode` header used by some endpoints for additional verification.
