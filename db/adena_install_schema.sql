-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 15, 2026 at 10:59 AM
-- Server version: 11.4.10-MariaDB-cll-lve
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `adey8293_adena`
--

-- --------------------------------------------------------

--
-- Table structure for table `api_logs`
--

CREATE TABLE `api_logs` (
  `id` bigint(20) NOT NULL,
  `token_id` int(11) DEFAULT NULL,
  `token_name` varchar(120) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(12) NOT NULL,
  `permission_key` varchar(80) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_request_logs`
--

CREATE TABLE `api_request_logs` (
  `id` bigint(20) NOT NULL,
  `token_id` int(11) DEFAULT NULL,
  `client_type` varchar(30) DEFAULT NULL,
  `endpoint` varchar(190) NOT NULL,
  `method` varchar(10) NOT NULL,
  `permission` varchar(80) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `device_code` varchar(40) DEFAULT NULL,
  `api_mode` varchar(20) NOT NULL DEFAULT 'sender',
  `branch_id` int(11) DEFAULT NULL,
  `unit_code` varchar(40) DEFAULT NULL,
  `remote_base_url` varchar(255) DEFAULT NULL,
  `remote_token` text DEFAULT NULL,
  `token_plain` text DEFAULT NULL,
  `api_type` varchar(50) DEFAULT NULL,
  `client_type` varchar(30) NOT NULL DEFAULT 'pos_desktop',
  `permissions` text DEFAULT NULL,
  `allowed_ips` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_token_permissions`
--

CREATE TABLE `api_token_permissions` (
  `id` int(11) NOT NULL,
  `token_id` int(11) NOT NULL,
  `permission_key` varchar(80) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bom_headers`
--

CREATE TABLE `bom_headers` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `finished_product_id` int(11) NOT NULL,
  `bom_code` varchar(50) NOT NULL,
  `bom_name` varchar(160) NOT NULL,
  `yield_qty` decimal(18,4) NOT NULL DEFAULT 1.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bom_items`
--

CREATE TABLE `bom_items` (
  `id` int(11) NOT NULL,
  `bom_id` int(11) NOT NULL,
  `material_product_id` int(11) NOT NULL,
  `qty_per_yield` decimal(18,4) NOT NULL,
  `unit_note` varchar(120) DEFAULT NULL,
  `wastage_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `branch_code` varchar(40) NOT NULL,
  `branch_name` varchar(120) NOT NULL,
  `unit_type` enum('branch','kitchen') NOT NULL DEFAULT 'branch',
  `is_kitchen` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branch_product_prices`
--

CREATE TABLE `branch_product_prices` (
  `id` bigint(20) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branch_stock_inputs`
--

CREATE TABLE `branch_stock_inputs` (
  `id` bigint(20) NOT NULL,
  `input_no` varchar(80) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'approved',
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `username` varchar(60) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `domicile` varchar(120) DEFAULT NULL,
  `instagram` varchar(120) DEFAULT NULL,
  `loyalty_points` int(11) NOT NULL DEFAULT 0,
  `loyalty_remainder` int(11) NOT NULL DEFAULT 0,
  `email` varchar(190) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_sessions`
--

CREATE TABLE `customer_sessions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `device_tokens`
--

CREATE TABLE `device_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_name` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `guides`
--

CREATE TABLE `guides` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `initial_stock_entries`
--

CREATE TABLE `initial_stock_entries` (
  `id` bigint(20) NOT NULL,
  `location_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(18,2) DEFAULT NULL,
  `status` enum('posted','owner_override_requested','owner_override_approved','void') NOT NULL DEFAULT 'posted',
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_rewards`
--

CREATE TABLE `loyalty_rewards` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `points_required` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_code` varchar(40) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `status` enum('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `price_each` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_cash_movements`
--

CREATE TABLE `pos_cash_movements` (
  `id` bigint(20) NOT NULL,
  `shift_id` bigint(20) NOT NULL,
  `movement_type` varchar(10) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `offline_uuid` varchar(80) DEFAULT NULL,
  `sync_status` varchar(20) NOT NULL DEFAULT 'synced'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_pending_orders`
--

CREATE TABLE `pos_pending_orders` (
  `id` bigint(20) NOT NULL,
  `local_pending_id` varchar(120) NOT NULL,
  `pending_code` varchar(80) DEFAULT NULL,
  `cashier_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `customer_name` varchar(160) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_type` varchar(20) NOT NULL DEFAULT 'fixed',
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid','deleted') NOT NULL DEFAULT 'pending',
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_pending_order_items`
--

CREATE TABLE `pos_pending_order_items` (
  `id` bigint(20) NOT NULL,
  `pending_order_id` bigint(20) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(190) DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `price_each` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_type` varchar(20) NOT NULL DEFAULT 'fixed',
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `include_in_sales_report` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_print_jobs`
--

CREATE TABLE `pos_print_jobs` (
  `id` bigint(20) NOT NULL,
  `job_token` varchar(100) NOT NULL,
  `sale_id` bigint(20) DEFAULT NULL,
  `receipt_payload` longtext NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` enum('pending','printed','expired','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `printed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `device_hint` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_shifts`
--

CREATE TABLE `pos_shifts` (
  `id` bigint(20) NOT NULL,
  `shift_code` varchar(60) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `opened_by` int(11) NOT NULL,
  `opening_cash_default` decimal(15,2) NOT NULL DEFAULT 0.00,
  `opening_cash_actual` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `expected_cash_total` decimal(15,2) DEFAULT NULL,
  `counted_cash_total` decimal(15,2) DEFAULT NULL,
  `cash_difference` decimal(15,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `offline_open_uuid` varchar(80) DEFAULT NULL,
  `offline_close_uuid` varchar(80) DEFAULT NULL,
  `sync_status` varchar(20) NOT NULL DEFAULT 'synced',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_shift_users`
--

CREATE TABLE `pos_shift_users` (
  `id` bigint(20) NOT NULL,
  `shift_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(40) NOT NULL DEFAULT 'join',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sync_queue_log`
--

CREATE TABLE `pos_sync_queue_log` (
  `id` bigint(20) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `offline_uuid` varchar(80) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'success',
  `message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_headers`
--

CREATE TABLE `production_headers` (
  `id` int(11) NOT NULL,
  `production_no` varchar(50) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `bom_id` int(11) NOT NULL,
  `finished_product_id` int(11) NOT NULL,
  `production_date` date NOT NULL,
  `qty_to_produce` decimal(18,4) NOT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `mode_source` enum('manual_menu','pos_auto') NOT NULL DEFAULT 'manual_menu',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_items`
--

CREATE TABLE `production_items` (
  `id` int(11) NOT NULL,
  `production_id` int(11) NOT NULL,
  `material_product_id` int(11) NOT NULL,
  `required_qty` decimal(18,4) NOT NULL,
  `actual_qty` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(180) NOT NULL,
  `category` varchar(120) DEFAULT NULL,
  `is_best_seller` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `kitchen_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `min_stock_level` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `product_type` enum('finished_good','raw_material','service') NOT NULL DEFAULT 'finished_good',
  `track_stock` tinyint(1) NOT NULL DEFAULT 1,
  `is_price_editable` tinyint(1) NOT NULL DEFAULT 0,
  `include_in_sales_report` tinyint(1) NOT NULL DEFAULT 1,
  `reorder_level` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `allow_direct_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `allow_bom` tinyint(1) NOT NULL DEFAULT 0,
  `base_unit` varchar(50) DEFAULT NULL,
  `purchase_unit` varchar(50) DEFAULT NULL,
  `purchase_to_base_factor` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `sale_unit` varchar(50) DEFAULT NULL,
  `sale_to_base_factor` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `show_on_pos` tinyint(1) NOT NULL DEFAULT 1,
  `show_on_landing` tinyint(1) NOT NULL DEFAULT 1,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_headers`
--

CREATE TABLE `purchase_headers` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_no` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `purchase_type` enum('raw_material','general') NOT NULL DEFAULT 'raw_material',
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `item_name` varchar(190) DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,2) NOT NULL,
  `line_total` decimal(18,2) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_revision_audit`
--

CREATE TABLE `purchase_revision_audit` (
  `id` bigint(20) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `purchase_no` varchar(50) NOT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT current_timestamp(),
  `edit_reason` text DEFAULT NULL,
  `snapshot_before` longtext DEFAULT NULL,
  `snapshot_after` longtext DEFAULT NULL,
  `change_summary` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qris_banks`
--

CREATE TABLE `qris_banks` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_key` varchar(50) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` bigint(20) NOT NULL,
  `role_id` int(11) NOT NULL,
  `menu_key` varchar(80) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_print` tinyint(1) NOT NULL DEFAULT 0,
  `can_export` tinyint(1) NOT NULL DEFAULT 0,
  `can_approve` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `transaction_code` varchar(40) DEFAULT NULL,
  `transaction_group_uuid` varchar(80) DEFAULT NULL,
  `offline_uuid` varchar(80) DEFAULT NULL,
  `sync_status` varchar(20) NOT NULL DEFAULT 'synced',
  `base_sale_code` varchar(60) DEFAULT NULL,
  `revision_suffix` varchar(10) DEFAULT NULL,
  `revision_no` int(11) NOT NULL DEFAULT 0,
  `is_active_revision` tinyint(1) NOT NULL DEFAULT 1,
  `revised_from_sale_id` int(11) DEFAULT NULL,
  `revision_reason_category` varchar(120) DEFAULT NULL,
  `revision_reason_text` text DEFAULT NULL,
  `revised_by_user_id` int(11) DEFAULT NULL,
  `revised_at` datetime DEFAULT NULL,
  `revision_status` varchar(30) NOT NULL DEFAULT 'active',
  `original_sale_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `sale_source` varchar(30) NOT NULL DEFAULT 'branch_pos',
  `unit_type` varchar(30) DEFAULT NULL,
  `shift_id` bigint(20) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `price_each` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `sold_at` timestamp NULL DEFAULT current_timestamp(),
  `payment_bank` varchar(100) DEFAULT NULL,
  `guide_name` varchar(100) DEFAULT NULL,
  `discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_type` varchar(10) NOT NULL DEFAULT 'fixed',
  `tx_discount_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tx_discount_type` varchar(10) NOT NULL DEFAULT 'fixed',
  `include_in_sales_report` tinyint(1) NOT NULL DEFAULT 1,
  `line_subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `line_net_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pending_order_id` bigint(20) DEFAULT NULL,
  `local_device_id` varchar(120) DEFAULT NULL,
  `local_transaction_id` varchar(120) DEFAULT NULL,
  `payment_channel_id` bigint(20) DEFAULT NULL,
  `payment_channel_name` varchar(120) DEFAULT NULL,
  `guide_id` bigint(20) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `payment_summary` text DEFAULT NULL,
  `customer_id` bigint(20) DEFAULT NULL,
  `returned_by` bigint(20) DEFAULT NULL,
  `return_status` varchar(30) NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_production_links`
--

CREATE TABLE `sales_production_links` (
  `id` bigint(20) NOT NULL,
  `transaction_code` varchar(40) NOT NULL,
  `production_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns`
--

CREATE TABLE `sales_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `offline_uuid` varchar(120) DEFAULT NULL,
  `transaction_group_uuid` varchar(120) DEFAULT NULL,
  `local_transaction_id` varchar(120) DEFAULT NULL,
  `transaction_code` varchar(120) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `total_return` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `sync_status` varchar(30) NOT NULL DEFAULT 'synced'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_return_items`
--

CREATE TABLE `sales_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `return_id` bigint(20) DEFAULT NULL,
  `sale_id` bigint(20) DEFAULT NULL,
  `product_id` bigint(20) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `price_each` decimal(14,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_payments`
--

CREATE TABLE `sale_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) DEFAULT NULL,
  `transaction_group_uuid` varchar(120) DEFAULT NULL,
  `local_transaction_id` varchar(120) DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_bank` varchar(120) DEFAULT NULL,
  `payment_bank_id` bigint(20) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `fee_percent` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `fee_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `charged_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cash_received` decimal(15,2) DEFAULT NULL,
  `cash_change` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `key` varchar(80) NOT NULL,
  `value` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_ledger`
--

CREATE TABLE `stock_ledger` (
  `id` bigint(20) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `trans_type` varchar(60) NOT NULL,
  `ref_table` varchar(60) NOT NULL,
  `ref_id` bigint(20) NOT NULL,
  `qty_in` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `qty_out` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(18,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_locations`
--

CREATE TABLE `stock_locations` (
  `id` int(11) NOT NULL,
  `location_code` varchar(40) NOT NULL,
  `location_name` varchar(160) NOT NULL,
  `location_type` enum('kitchen','store','branch') NOT NULL DEFAULT 'branch',
  `branch_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_opname_headers`
--

CREATE TABLE `stock_opname_headers` (
  `id` bigint(20) NOT NULL,
  `opname_no` varchar(80) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `opname_date` date NOT NULL,
  `status` enum('draft','waiting_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `approval_note` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_opname_items`
--

CREATE TABLE `stock_opname_items` (
  `id` bigint(20) NOT NULL,
  `opname_id` bigint(20) NOT NULL,
  `product_id` int(11) NOT NULL,
  `system_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `physical_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `variance_qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `variance_type` enum('plus','minus','zero') NOT NULL DEFAULT 'zero',
  `reason_note` varchar(255) DEFAULT NULL,
  `line_note` varchar(255) DEFAULT NULL,
  `warning_flag` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `id` bigint(20) NOT NULL,
  `transfer_no` varchar(60) NOT NULL,
  `from_location_id` int(11) NOT NULL,
  `to_location_id` int(11) NOT NULL,
  `status` enum('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `transfer_type` varchar(30) NOT NULL DEFAULT 'stock_transfer',
  `sent_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `receiver_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfer_headers`
--

CREATE TABLE `stock_transfer_headers` (
  `id` int(11) NOT NULL,
  `transfer_no` varchar(50) NOT NULL,
  `transfer_date` date NOT NULL,
  `source_branch_id` int(11) NOT NULL,
  `dest_branch_id` int(11) NOT NULL,
  `status` enum('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfer_items`
--

CREATE TABLE `stock_transfer_items` (
  `id` bigint(20) NOT NULL,
  `transfer_id` bigint(20) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(18,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(40) NOT NULL,
  `supplier_name` varchar(160) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `role` enum('owner','admin','manager','manager_cabang','pegawai_cabang','kasir','gudang','user','pegawai') NOT NULL DEFAULT 'admin',
  `role_id` int(11) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_branches`
--

CREATE TABLE `user_branches` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_invites`
--

CREATE TABLE `user_invites` (
  `id` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `role` varchar(30) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `api_logs`
--
ALTER TABLE `api_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_api_logs_created` (`created_at`),
  ADD KEY `idx_api_logs_token` (`token_id`),
  ADD KEY `idx_api_logs_endpoint` (`endpoint`);

--
-- Indexes for table `api_request_logs`
--
ALTER TABLE `api_request_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_api_logs_token` (`token_id`),
  ADD KEY `idx_api_logs_created` (`created_at`),
  ADD KEY `idx_api_logs_endpoint` (`endpoint`);

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_api_tokens_active` (`is_active`),
  ADD KEY `idx_api_tokens_branch` (`branch_id`),
  ADD KEY `idx_api_tokens_client_type` (`client_type`),
  ADD KEY `idx_api_tokens_branch_id` (`branch_id`),
  ADD KEY `idx_api_tokens_unit_code` (`unit_code`),
  ADD KEY `idx_api_tokens_device` (`device_code`),
  ADD KEY `idx_api_tokens_unit` (`unit_code`);

--
-- Indexes for table `api_token_permissions`
--
ALTER TABLE `api_token_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_api_token_permission` (`token_id`,`permission_key`),
  ADD KEY `idx_api_token_permissions_token` (`token_id`);

--
-- Indexes for table `bom_headers`
--
ALTER TABLE `bom_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_bom_code` (`bom_code`),
  ADD KEY `idx_bom_finished_active` (`finished_product_id`,`is_active`);

--
-- Indexes for table `bom_items`
--
ALTER TABLE `bom_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_bom_material` (`bom_id`,`material_product_id`),
  ADD KEY `idx_bom_items_lookup` (`bom_id`,`material_product_id`),
  ADD KEY `fk_bom_items_material` (`material_product_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_branch_code` (`branch_code`),
  ADD KEY `idx_branches_unit_type` (`unit_type`,`is_active`);

--
-- Indexes for table `branch_product_prices`
--
ALTER TABLE `branch_product_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_branch_product_price` (`branch_id`,`product_id`),
  ADD UNIQUE KEY `uniq_branch_product_price` (`branch_id`,`product_id`),
  ADD KEY `idx_bpp_product` (`product_id`),
  ADD KEY `idx_bpp_active` (`branch_id`,`is_active`),
  ADD KEY `idx_bpp_branch` (`branch_id`);

--
-- Indexes for table `branch_stock_inputs`
--
ALTER TABLE `branch_stock_inputs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_branch_stock_input_no` (`input_no`),
  ADD KEY `idx_branch_stock_inputs_branch_status` (`branch_id`,`status`,`created_at`),
  ADD KEY `idx_branch_stock_inputs_product` (`product_id`),
  ADD KEY `idx_branch_stock_inputs_created_by` (`created_by`),
  ADD KEY `idx_branch_stock_inputs_approved_by` (`approved_by`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uniq_phone` (`phone`),
  ADD UNIQUE KEY `uniq_customers_username` (`username`),
  ADD UNIQUE KEY `uniq_customers_email` (`email`);

--
-- Indexes for table `customer_sessions`
--
ALTER TABLE `customer_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_customer_id` (`customer_id`);

--
-- Indexes for table `device_tokens`
--
ALTER TABLE `device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `guides`
--
ALTER TABLE `guides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_guide_name` (`name`);

--
-- Indexes for table `initial_stock_entries`
--
ALTER TABLE `initial_stock_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_initial_stock_once` (`location_id`,`product_id`),
  ADD KEY `idx_initial_stock_location` (`location_id`,`status`);

--
-- Indexes for table `loyalty_rewards`
--
ALTER TABLE `loyalty_rewards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_product` (`product_id`),
  ADD KEY `idx_points` (`points_required`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `pos_cash_movements`
--
ALTER TABLE `pos_cash_movements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cash_offline_uuid` (`offline_uuid`),
  ADD KEY `idx_shift_movement` (`shift_id`,`movement_type`);

--
-- Indexes for table `pos_pending_orders`
--
ALTER TABLE `pos_pending_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_pos_pending_local` (`local_pending_id`),
  ADD KEY `idx_pos_pending_status` (`status`,`branch_id`);

--
-- Indexes for table `pos_pending_order_items`
--
ALTER TABLE `pos_pending_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pending_items_order` (`pending_order_id`);

--
-- Indexes for table `pos_print_jobs`
--
ALTER TABLE `pos_print_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `job_token` (`job_token`),
  ADD KEY `idx_status_expires` (`status`,`expires_at`),
  ADD KEY `idx_sale_id` (`sale_id`);

--
-- Indexes for table `pos_shifts`
--
ALTER TABLE `pos_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_shift_code` (`shift_code`),
  ADD UNIQUE KEY `uniq_shift_open_uuid` (`offline_open_uuid`),
  ADD UNIQUE KEY `uniq_shift_close_uuid` (`offline_close_uuid`),
  ADD KEY `idx_shift_status` (`status`,`opened_at`),
  ADD KEY `idx_shift_branch_status` (`branch_id`,`status`),
  ADD KEY `idx_pos_shifts_branch_status` (`branch_id`,`status`,`opened_at`);

--
-- Indexes for table `pos_shift_users`
--
ALTER TABLE `pos_shift_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_user` (`shift_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `pos_sync_queue_log`
--
ALTER TABLE `pos_sync_queue_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sync_offline_uuid` (`offline_uuid`),
  ADD KEY `idx_sync_status` (`status`,`processed_at`);

--
-- Indexes for table `production_headers`
--
ALTER TABLE `production_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_production_no` (`production_no`),
  ADD KEY `idx_production_branch_status_date` (`branch_id`,`status`,`production_date`);

--
-- Indexes for table `production_items`
--
ALTER TABLE `production_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_production_items_prod` (`production_id`),
  ADD KEY `idx_production_items_material` (`material_product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_type` (`product_type`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);

--
-- Indexes for table `purchase_headers`
--
ALTER TABLE `purchase_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_purchase_no` (`purchase_no`),
  ADD KEY `idx_purchase_branch_status_date` (`branch_id`,`status`,`purchase_date`),
  ADD KEY `idx_purchase_supplier` (`supplier_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_items_purchase` (`purchase_id`),
  ADD KEY `idx_purchase_items_product` (`product_id`);

--
-- Indexes for table `purchase_revision_audit`
--
ALTER TABLE `purchase_revision_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purchase_revision_purchase` (`purchase_id`,`edited_at`),
  ADD KEY `idx_purchase_revision_no` (`purchase_no`);

--
-- Indexes for table `qris_banks`
--
ALTER TABLE `qris_banks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_role_key` (`role_key`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_role_menu` (`role_id`,`menu_key`),
  ADD KEY `idx_menu` (`menu_key`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sales_offline_uuid` (`offline_uuid`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sales_revision_active` (`is_active_revision`,`sold_at`),
  ADD KEY `idx_sales_revision_base` (`base_sale_code`,`revision_no`),
  ADD KEY `idx_sales_shift_id` (`shift_id`),
  ADD KEY `idx_sales_tx_group` (`transaction_group_uuid`),
  ADD KEY `idx_sales_device_local` (`local_device_id`,`local_transaction_id`),
  ADD KEY `idx_sales_unit_date` (`branch_id`,`sale_source`,`sold_at`),
  ADD KEY `idx_sales_branch_tx` (`branch_id`,`transaction_code`),
  ADD KEY `idx_sales_branch_sold` (`branch_id`,`sold_at`);

--
-- Indexes for table `sales_production_links`
--
ALTER TABLE `sales_production_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sales_prod_tx` (`transaction_code`),
  ADD KEY `idx_sales_prod_prod` (`production_id`);

--
-- Indexes for table `sales_returns`
--
ALTER TABLE `sales_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `offline_uuid` (`offline_uuid`);

--
-- Indexes for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sale_payments`
--
ALTER TABLE `sale_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale_payments_local_tx` (`local_transaction_id`),
  ADD KEY `idx_sale_payments_group` (`transaction_group_uuid`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_ledger_main` (`branch_id`,`product_id`,`created_at`),
  ADD KEY `idx_stock_ledger_ref` (`ref_table`,`ref_id`),
  ADD KEY `idx_stock_ledger_location` (`location_id`,`product_id`,`created_at`);

--
-- Indexes for table `stock_locations`
--
ALTER TABLE `stock_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_locations_code` (`location_code`),
  ADD KEY `idx_stock_locations_type` (`location_type`,`is_active`);

--
-- Indexes for table `stock_opname_headers`
--
ALTER TABLE `stock_opname_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_opname_no` (`opname_no`),
  ADD KEY `idx_stock_opname_branch_status_date` (`branch_id`,`status`,`opname_date`),
  ADD KEY `idx_stock_opname_created_by` (`created_by`),
  ADD KEY `idx_stock_opname_approved_by` (`approved_by`);

--
-- Indexes for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_opname_item` (`opname_id`,`product_id`),
  ADD KEY `idx_stock_opname_item_product` (`product_id`);

--
-- Indexes for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_stock_transfer_no` (`transfer_no`),
  ADD KEY `idx_stock_transfer_status` (`status`,`from_location_id`,`to_location_id`),
  ADD KEY `idx_transfer_from_to_status` (`from_location_id`,`to_location_id`,`status`);

--
-- Indexes for table `stock_transfer_headers`
--
ALTER TABLE `stock_transfer_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_transfer_no` (`transfer_no`),
  ADD KEY `idx_transfer_status` (`source_branch_id`,`dest_branch_id`,`status`,`transfer_date`);

--
-- Indexes for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer_items_transfer` (`transfer_id`),
  ADD KEY `idx_transfer_items_product` (`product_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_code` (`supplier_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_role_id` (`role_id`);

--
-- Indexes for table `user_branches`
--
ALTER TABLE `user_branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_branch` (`user_id`,`branch_id`),
  ADD KEY `idx_user_branches_user` (`user_id`),
  ADD KEY `idx_user_branches_branch` (`branch_id`);

--
-- Indexes for table `user_invites`
--
ALTER TABLE `user_invites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `api_logs`
--
ALTER TABLE `api_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_request_logs`
--
ALTER TABLE `api_request_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_token_permissions`
--
ALTER TABLE `api_token_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom_headers`
--
ALTER TABLE `bom_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bom_items`
--
ALTER TABLE `bom_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branch_product_prices`
--
ALTER TABLE `branch_product_prices`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branch_stock_inputs`
--
ALTER TABLE `branch_stock_inputs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_sessions`
--
ALTER TABLE `customer_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `device_tokens`
--
ALTER TABLE `device_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `guides`
--
ALTER TABLE `guides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `initial_stock_entries`
--
ALTER TABLE `initial_stock_entries`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loyalty_rewards`
--
ALTER TABLE `loyalty_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_cash_movements`
--
ALTER TABLE `pos_cash_movements`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_pending_orders`
--
ALTER TABLE `pos_pending_orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_pending_order_items`
--
ALTER TABLE `pos_pending_order_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_print_jobs`
--
ALTER TABLE `pos_print_jobs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_shifts`
--
ALTER TABLE `pos_shifts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_shift_users`
--
ALTER TABLE `pos_shift_users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sync_queue_log`
--
ALTER TABLE `pos_sync_queue_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_headers`
--
ALTER TABLE `production_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_items`
--
ALTER TABLE `production_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_headers`
--
ALTER TABLE `purchase_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_revision_audit`
--
ALTER TABLE `purchase_revision_audit`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qris_banks`
--
ALTER TABLE `qris_banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_production_links`
--
ALTER TABLE `sales_production_links`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns`
--
ALTER TABLE `sales_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_payments`
--
ALTER TABLE `sale_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_ledger`
--
ALTER TABLE `stock_ledger`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_locations`
--
ALTER TABLE `stock_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_opname_headers`
--
ALTER TABLE `stock_opname_headers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transfer_headers`
--
ALTER TABLE `stock_transfer_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_branches`
--
ALTER TABLE `user_branches`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_invites`
--
ALTER TABLE `user_invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bom_items`
--
ALTER TABLE `bom_items`
  ADD CONSTRAINT `fk_bom_items_header` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bom_items_material` FOREIGN KEY (`material_product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `customer_sessions`
--
ALTER TABLE `customer_sessions`
  ADD CONSTRAINT `customer_sessions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `device_tokens`
--
ALTER TABLE `device_tokens`
  ADD CONSTRAINT `device_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loyalty_rewards`
--
ALTER TABLE `loyalty_rewards`
  ADD CONSTRAINT `loyalty_rewards_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_cash_movements`
--
ALTER TABLE `pos_cash_movements`
  ADD CONSTRAINT `fk_pos_cash_shift` FOREIGN KEY (`shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_shift_users`
--
ALTER TABLE `pos_shift_users`
  ADD CONSTRAINT `fk_pos_shift_users_shift` FOREIGN KEY (`shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_items`
--
ALTER TABLE `production_items`
  ADD CONSTRAINT `fk_production_items_header` FOREIGN KEY (`production_id`) REFERENCES `production_headers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_production_items_material` FOREIGN KEY (`material_product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_purchase_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchase_headers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD CONSTRAINT `fk_stock_opname_items_header` FOREIGN KEY (`opname_id`) REFERENCES `stock_opname_headers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_opname_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
