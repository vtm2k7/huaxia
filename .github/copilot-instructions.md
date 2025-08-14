# AI Agent Instructions for Huaxia Project

## Project Overview
This is a ThinkPHP-based cemetery management system with integrated WeChat Pay and e-invoice (Baiwang) capabilities.

## Core Components

### 1. Controller Architecture
- All controllers extend `Base` controller
- Located in `app/controller/`
- Key controllers:
  - `Pay.php`: WeChat payment processing
  - `Smb.php`: Baiwang e-invoice integration
  - `User.php`: User management
  - `Appointment.php`: Cemetery appointment handling

### 2. Payment Integration (Pay.php)
```php
// Order number prefix convention
const TRADE_PREFIX = '2025HX';

// Mock payment support for testing
// Controlled by IS_MOCK_PAY environment variable
if (env('IS_MOCK_PAY', false) == 'true') {
    // Mock flow
}
```

### 3. E-Invoice Integration (Smb.php)
- Uses Baiwang API for e-invoice generation
- Automatic invoice generation after successful payment
- Tax-free invoice handling for cemetery services

## Development Workflows

### Local Development
1. Environment Setup
   - Copy `.env.example` to `.env`
   - Set `IS_MOCK_PAY=true` for payment testing

### Testing
- Use mock payment mode for payment flow testing
- API endpoints:
  - `/pay/verify`: User verification
  - `/pay/unifiedOrder`: Create payment order
  - `/smb/invoice`: Generate invoice
  - `/smb/query`: Query invoice status

## Project-Specific Conventions

### Error Handling
```php
// Standard error response format
return json([
    'success' => false,
    'message' => $error_message
], $status_code);
```

### Logging
- Use `think\facade\Log` for all logging
- Log patterns:
  - Payment operations: prefix with '----pay----'
  - Notifications: prefix with '----notify----'

### External Integration Points
1. WeChat Pay API
   - Base URL: `http://api.weixin.qq.com/_/pay/`
   - Required headers: `x-wx-env`, `x-forwarded-for`

2. Remote System Integration
   - Base URL: `http://huaxia.ad-wizard.cn/mini/`
   - Key endpoints:
     - `/verify`: User verification
     - `/updateFee`: Update maintenance fee status

## Known Limitations
- Payment callback handling may receive duplicate notifications
- Invoice generation is synchronous and may impact response time

## Common Tasks
1. Adding new payment type:
   - Extend `Pay.php`
   - Follow existing pattern in `unifiedOrder()`
   
2. Modifying invoice template:
   - Update invoice parameters in `Smb.php`
   - Test with mock payment first
