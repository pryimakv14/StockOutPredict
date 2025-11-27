# StockOutPredict - Magento 2 Module

A comprehensive Magento 2 module that predicts stockout scenarios using machine learning models (Prophet) and provides real-time alerts to administrators. The module integrates with an external API for model training, prediction, and accuracy validation.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Architecture](#architecture)
- [API Integration](#api-integration)
- [Components](#components)
- [Console Commands](#console-commands)
- [Admin Interface](#admin-interface)
- [Events & Observers](#events--observers)
- [Troubleshooting](#troubleshooting)
- [Support](#support)

## Overview

StockOutPredict is a Magento 2 extension that leverages machine learning to predict when products will run out of stock. The module:

- Monitors product stock levels in real-time
- Uses Prophet forecasting models to predict stockout dates
- Sends admin notifications when products are predicted to run out within a configured threshold
- Provides accuracy validation tools to assess model performance
- Supports per-SKU model parameter configuration
- Exports sales history data for model training

## Features

### Core Functionality

- **Real-time Stockout Prediction**: Automatically predicts stockout dates when orders are placed
- **Admin Notifications**: Creates admin panel notifications for low stock warnings
- **Per-SKU Configuration**: Configure model parameters individually for each product SKU
- **Model Training**: Export sales data and train ML models via console command
- **Accuracy Validation**: Visualize and validate model accuracy through admin interface
- **Cooldown Period**: Prevents excessive API calls with configurable cooldown periods
- **Parameter Locking**: Lock model parameters or skip training for specific SKUs

### Model Parameters

The module supports the following Prophet model parameters:

- **Alert Threshold**: Days remaining threshold to trigger notifications
- **Changepoint Prior Scale (CPS)**: Flexibility for trend changes (typical: 0.05, 0.1, 0.5)
- **Seasonality Prior Scale (SPS)**: Strength of seasonality components (typical: 5.0, 10.0)
- **Holidays Prior Scale (HPS)**: Impact of holidays on forecast (typical: 10.0)
- **Seasonality Mode**: Additive or Multiplicative
- **Yearly Seasonality**: Enable/disable yearly patterns
- **Weekly Seasonality**: Enable/disable weekly patterns
- **Daily Seasonality**: Enable/disable daily patterns

## Requirements

- **Magento 2.4.x** or higher
- **PHP 8.1+** (with strict types support)
- **External ML API**: A compatible API endpoint for predictions and model training
- **Magento Modules**:
  - `Magento_Sales`
  - `Magento_AdminNotification`

## Installation

### Manual Installation

1. Copy the module to your Magento installation:
   ```bash
   cp -r StockOutPredict app/code/Pryv/StockOutPredict
   ```

2. Enable the module:
   ```bash
   php bin/magento module:enable Pryv_StockOutPredict
   ```

3. Run setup upgrade:
   ```bash
   php bin/magento setup:upgrade
   ```

4. Compile DI and clear cache:
   ```bash
   php bin/magento setup:di:compile
   php bin/magento cache:clean
   ```

### Composer Installation (if available)

```bash
composer require pryv/stockout-predict
php bin/magento module:enable Pryv_StockOutPredict
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:clean
```

## Configuration

### System Configuration

Navigate to **Stores > Configuration > Sales > Stockout Prediction > Accuracy Validation**

#### API Base URL
- **Path**: `stockout/accuracy_validation/api_base_url`
- **Description**: Base URL for the ML API endpoints
- **Example**: `https://api.example.com/ml-service`

#### Prediction Cooldown Period
- **Path**: `stockout/accuracy_validation/prediction_cooldown_hours`
- **Description**: Hours to wait before making another prediction for the same SKU
- **Default**: 3 hours
- **Note**: Set to 0 or empty to disable cooldown

#### SKU Parameters
- **Path**: `stockout/accuracy_validation/sku_parameters`
- **Description**: Configure model parameters for each SKU

**Field Descriptions:**

| Field | Description | Example Values |
|-------|-------------|----------------|
| Product SKU | Product SKU identifier | `PROD-001` |
| Alert Threshold | Days remaining to trigger alert | `7`, `14`, `30` |
| CPS | Changepoint Prior Scale | `0.05`, `0.1`, `0.5` |
| SPS | Seasonality Prior Scale | `5.0`, `10.0` |
| HPS | Holidays Prior Scale | `10.0` |
| SM | Seasonality Mode | `additive`, `multiplicative` |
| Yearly Seasonality | Enable yearly patterns | `Yes`, `No` |
| Weekly Seasonality | Enable weekly patterns | `Yes`, `No` |
| Lock | Parameter locking mode | `None`, `Params`, `Model` |

**Lock Options:**
- **None**: Parameters not sent to API, model can update parameters during training
- **Params**: Parameters sent in request body, but not updated during training
- **Model**: Training skipped entirely for this SKU

### ACL Permissions

The module requires the following ACL resource:
- `Pryv_StockOutPredict::stockout_predict`

Assign this permission to admin users who need access to:
- Configuration settings
- Accuracy validation page

## Usage

### Initial Setup

1. **Configure API Base URL**
   - Go to Stores > Configuration > Sales > Stockout Prediction
   - Enter your ML API base URL

2. **Add SKU Parameters**
   - In the same configuration section, add SKUs you want to monitor
   - Configure alert thresholds and model parameters for each SKU

3. **Export and Train Models**
   ```bash
   php bin/magento stockout-predict:data:export
   ```
   This command will:
   - Export sales history for configured SKUs
   - Upload data to the API
   - Train models for all SKUs (unless locked)

### Monitoring Stockouts

Once configured, the module automatically:

1. **Monitors Orders**: When an order is placed, the module checks if any items match configured SKUs
2. **Checks Cooldown**: Verifies if a prediction was made recently (within cooldown period)
3. **Fetches Prediction**: Calls the API to get stockout prediction
4. **Creates Notification**: If days remaining < alert threshold, creates admin notification
5. **Sets Flag**: Records prediction timestamp to enforce cooldown

### Viewing Notifications

Admin notifications appear in:
- **Admin Panel > Notifications** (bell icon in top right)
- Notification format: "Low Stock Warning: [SKU] - Product SKU [SKU] is predicted to run out of stock in [X] days."

### Accuracy Validation

1. Navigate to **Reports > Stockout Accuracy Validation**
2. Enter a SKU in the input field
3. Click **"Reload Chart Data"**
4. View predicted vs actual sales comparison chart
5. Review accuracy metrics:
   - **MAE** (Mean Absolute Error)
   - **RMSE** (Root Mean Square Error)
   - **MAPE** (Mean Absolute Percentage Error)
   - **MBE** (Mean Bias Error)
   - **R²** (Coefficient of Determination)

## Architecture

### Module Structure

```
Pryv/StockOutPredict/
├── Block/
│   └── Adminhtml/Form/Field/
│       ├── LockParams.php
│       ├── SeasonalityMode.php
│       ├── SkuParameters.php
│       └── YesNoOptions.php
├── Console/
│   └── Command/
│       └── ExportData.php
├── Controller/
│   └── Adminhtml/Accuracy/
│       ├── FetchData.php
│       └── Validation.php
├── etc/
│   ├── acl.xml
│   ├── adminhtml/
│   │   ├── menu.xml
│   │   ├── routes.xml
│   │   └── system.xml
│   ├── di.xml
│   ├── events.xml
│   └── module.xml
├── Model/
│   ├── ConfigService.php
│   ├── DataExporter.php
│   └── PredictService.php
├── Observer/
│   └── OrderPlaceAfter.php
├── view/
│   └── adminhtml/
│       ├── layout/
│       ├── templates/
│       └── web/
└── registration.php
```

### Key Classes

#### Models

- **`ConfigService`**: Manages configuration values, SKU parameters, and API URLs
- **`PredictService`**: Handles API communication for predictions, notifications, and cooldown management
- **`DataExporter`**: Exports sales history data to CSV format

#### Observers

- **`OrderPlaceAfter`**: Triggers stockout prediction when orders are placed

#### Controllers

- **`Validation`**: Renders the accuracy validation page
- **`FetchData`**: AJAX endpoint for fetching chart data from API

#### Console Commands

- **`ExportData`**: Exports sales data, uploads to API, and trains models

## API Integration

The module integrates with an external ML API that must provide the following endpoints:

### Endpoints

#### 1. Predict Stockout
```
GET /predict/{sku}?current_stock={quantity}
```
**Response:**
```json
{
  "days_of_stock_remaining": 15
}
```

#### 2. Upload Sales Data
```
POST /upload-data
Content-Type: multipart/form-data
Body: CSV file with columns: sku, qty_ordered, created_at
```

#### 3. Train Model
```
POST /train/{sku}
Content-Type: application/json
Body (optional): {
  "changepoint_prior_scale": 0.1,
  "seasonality_prior_scale": 10.0,
  "holidays_prior_scale": 10.0,
  "seasonality_mode": "additive",
  "yearly_seasonality": true,
  "weekly_seasonality": true,
  "daily_seasonality": false
}
```
**Response:**
```json
{
  "status": "success",
  "training_info": {
    "best_parameters": { ... },
    "parameters_used": { ... }
  }
}
```

#### 4. Validate Period Accuracy
```
POST /validate-period-accuracy/{sku}
Content-Type: application/json
Body: {
  "alert_threshold": 7,
  "changepoint_prior_scale": 0.1,
  ...
}
```
**Response:**
```json
{
  "predicted": [10, 12, 15, ...],
  "actual": [11, 13, 14, ...],
  "metrics": {
    "mae": 1.5,
    "rmse": 2.1,
    "mape": 8.5,
    "mbe": 0.3,
    "r_squared": 0.95
  }
}
```

### API Requirements

- All endpoints should return appropriate HTTP status codes
- JSON responses should be properly formatted
- Error responses should include descriptive messages
- Timeout handling: Predict (30s), Upload (10min), Train (30min)

## Components

### Configuration Service (`ConfigService`)

Manages all configuration-related operations:

- **`getApiBaseUrl()`**: Returns configured API base URL
- **`getUploadApiUrl()`**: Returns upload endpoint URL
- **`getTrainApiUrl($sku)`**: Returns training endpoint URL for a SKU
- **`getAllSkus()`**: Returns array of all configured SKUs
- **`getSkuParameters($sku)`**: Returns parameters for a specific SKU
- **`getAllSkuParameters($fresh)`**: Returns all SKU parameters
- **`updateSkuParameters($sku, $parameters)`**: Updates parameters for a SKU
- **`batchUpdateSkuParameters($updates)`**: Batch update multiple SKUs
- **`getPredictionCooldownHours()`**: Returns cooldown period in hours

### Prediction Service (`PredictService`)

Handles prediction and notification logic:

- **`getPrediction($sku, $quantity)`**: Fetches prediction from API
- **`createAdminNotification($sku, $daysRemaining)`**: Creates admin notification
- **`hasExistingNotification($sku)`**: Checks if notification exists
- **`wasPredictionMadeRecently($sku)`**: Checks cooldown period
- **`setPredictionFlag($sku)`**: Records prediction timestamp

### Data Exporter (`DataExporter`)

Exports sales history data:

- **`exportSalesHistory()`**: Exports sales data to CSV
- Exports only configured SKUs
- Filters orders before today
- Uses batch processing (1000 records per batch)
- Output: `var/export/sales_history.csv`

## Console Commands

### Export and Train Command

```bash
php bin/magento stockout-predict:data:export
```

**What it does:**

1. **Exports Sales History**
   - Queries order items for configured SKUs
   - Filters orders created before today
   - Exports to CSV: `var/export/sales_history.csv`
   - Format: `sku,qty_ordered,created_at`

2. **Uploads to API**
   - Uploads CSV file via multipart/form-data
   - Endpoint: `{api_base_url}/upload-data`

3. **Trains Models**
   - Iterates through all configured SKUs
   - Skips SKUs with "Model" lock
   - Sends parameters if "Params" lock is set
   - Updates parameters from response if no lock
   - Endpoint: `{api_base_url}/train/{sku}`

**Output:**
- Success/failure messages for each step
- File path of exported CSV
- Training results for each SKU
- Parameter updates summary

## Admin Interface

### Configuration Page

**Location**: Stores > Configuration > Sales > Stockout Prediction

**Features:**
- API Base URL configuration
- Cooldown period setting
- SKU parameters grid with:
  - Dynamic row addition
  - Dropdown selectors for seasonality mode and locks
  - Yes/No toggles for seasonality flags
  - Validation for numeric fields

### Accuracy Validation Page

**Location**: Reports > Stockout Accuracy Validation

**Features:**
- SKU input field
- Interactive chart showing predicted vs actual sales
- Accuracy metrics display:
  - MAE, RMSE, MAPE, MBE, R²
- Real-time data fetching via AJAX
- Canvas-based chart rendering

## Events & Observers

### Event: `checkout_submit_all_after`

**Observer**: `OrderPlaceAfter`

**Triggered**: After an order is successfully placed

**Logic:**
1. Retrieves order items
2. Filters items matching configured SKUs
3. Checks if SKU has alert threshold configured
4. Verifies no existing notification and cooldown period
5. Gets current stock quantity
6. Fetches prediction from API
7. Creates notification if days remaining < threshold
8. Sets prediction flag for cooldown

**Error Handling:**
- All exceptions are logged
- Failures don't interrupt order placement
- Detailed error logging with context

## Troubleshooting

### Common Issues

#### 1. Predictions Not Triggering

**Symptoms**: No notifications appearing after orders

**Solutions:**
- Verify SKU is configured in system configuration
- Check alert threshold is set and numeric
- Verify API base URL is correct
- Check cooldown period settings
- Review logs: `var/log/system.log` and `var/log/exception.log`

#### 2. API Connection Errors

**Symptoms**: Timeout or connection refused errors

**Solutions:**
- Verify API base URL is accessible from Magento server
- Check firewall rules
- Verify SSL certificates if using HTTPS
- Review API endpoint URLs in logs
- Check network connectivity

#### 3. Training Failures

**Symptoms**: Models not training during export command

**Solutions:**
- Verify SKU is not locked with "Model" option
- Check API endpoint is responding
- Review training timeout (30 minutes)
- Verify uploaded CSV has data
- Check API response format matches expected structure

#### 4. Accuracy Chart Not Loading

**Symptoms**: Chart shows "No data found" or errors

**Solutions:**
- Verify SKU exists in configuration
- Check API endpoint `/validate-period-accuracy/{sku}` is working
- Review browser console for JavaScript errors
- Verify admin user has ACL permissions
- Check API response format

#### 5. Notifications Not Appearing

**Symptoms**: Predictions work but no notifications

**Solutions:**
- Check notification exists in `adminnotification_inbox` table
- Verify admin user has permission to view notifications
- Check if notification was already dismissed
- Review notification creation logs

### Logging

The module logs important events to:

- **System Log**: `var/log/system.log`
- **Exception Log**: `var/log/exception.log`

**Log Entries Include:**
- API request/response details
- Prediction results
- Notification creation
- Configuration updates
- Error messages with stack traces

### Debug Mode

Enable Magento developer mode for detailed error messages:

```bash
php bin/magento deploy:mode:set developer
```

### Database Flags

The module uses the `flag` table to track predictions:

- **Flag Code Pattern**: `stockout_predict_{sku}`
- **Purpose**: Cooldown period enforcement
- **Fields**: `flag_code`, `state`, `flag_data`, `last_update`

To reset cooldown for a SKU:
```sql
DELETE FROM flag WHERE flag_code = 'stockout_predict_{SKU}';
```

## Support

### Module Information

- **Module Name**: Pryv_StockOutPredict
- **Namespace**: `Pryv\StockOutPredict`
- **Version**: Check `composer.json` or module version

### Dependencies

- Magento_Sales
- Magento_AdminNotification

### File Locations

- **Configuration**: `etc/adminhtml/system.xml`
- **ACL**: `etc/acl.xml`
- **Routes**: `etc/adminhtml/routes.xml`
- **Events**: `etc/events.xml`
- **DI**: `etc/di.xml`

### Additional Resources

- Check Magento logs for detailed error information
- Review API documentation for endpoint requirements
- Verify module is enabled: `php bin/magento module:status`

---

**Note**: This module requires an external ML API service. Ensure your API is properly configured and accessible before using the module in production.
