CREATE DATABASE IF NOT EXISTS `hamba` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hamba`;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('passenger','driver','admin') NOT NULL DEFAULT 'passenger',
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_api_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tokens_user (user_id),
  UNIQUE KEY uq_token_hash (token_hash),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS passengers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 5.00,
  completed_rides INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_passengers_user (user_id),
  CONSTRAINT fk_passengers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS drivers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  national_id_enc TEXT DEFAULT NULL,
  license_number VARCHAR(64) DEFAULT NULL,
  license_expiry DATE DEFAULT NULL,
  verification_status ENUM('pending','verified','rejected','suspended') NOT NULL DEFAULT 'pending',
  verification_notes TEXT DEFAULT NULL,
  operating_mode ENUM('private','taxi') NOT NULL DEFAULT 'private',
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  heading DECIMAL(6,2) DEFAULT NULL,
  speed_kmh DECIMAL(6,2) DEFAULT NULL,
  last_location_at DATETIME DEFAULT NULL,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 5.00,
  completed_rides INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_drivers_user (user_id),
  KEY idx_drivers_online (is_online, verification_status, operating_mode),
  CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  make VARCHAR(80) NOT NULL,
  model VARCHAR(80) NOT NULL,
  color VARCHAR(40) NOT NULL,
  plate_number VARCHAR(32) NOT NULL,
  vehicle_type ENUM('sedan','hatchback','suv','bakkie','minibus','kombi','other') NOT NULL DEFAULT 'sedan',
  seat_capacity INT UNSIGNED NOT NULL DEFAULT 4,
  photo_path VARCHAR(255) DEFAULT NULL,
  registration_info VARCHAR(190) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vehicles_driver (driver_id),
  CONSTRAINT fk_vehicles_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  doc_type ENUM('id','license','vehicle_registration','vehicle_photo','profile','other') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docs_driver (driver_id),
  CONSTRAINT fk_docs_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_verifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED DEFAULT NULL,
  previous_status VARCHAR(32) DEFAULT NULL,
  new_status VARCHAR(32) NOT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ver_driver (driver_id),
  CONSTRAINT fk_ver_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ver_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taxi_routes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  origin_name VARCHAR(80) NOT NULL,
  destination_name VARCHAR(80) NOT NULL,
  origin_lat DECIMAL(10,7) DEFAULT NULL,
  origin_lng DECIMAL(10,7) DEFAULT NULL,
  dest_lat DECIMAL(10,7) DEFAULT NULL,
  dest_lng DECIMAL(10,7) DEFAULT NULL,
  typical_duration_min INT UNSIGNED DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_route_pair (origin_name, destination_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taxi_shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  route_id INT UNSIGNED NOT NULL,
  status ENUM('active','ended') NOT NULL DEFAULT 'active',
  seat_capacity INT UNSIGNED NOT NULL,
  occupied_seats INT UNSIGNED NOT NULL DEFAULT 0,
  is_full TINYINT(1) NOT NULL DEFAULT 0,
  movement_status ENUM('moving','waiting') NOT NULL DEFAULT 'waiting',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME DEFAULT NULL,
  KEY idx_shifts_driver (driver_id, status),
  KEY idx_shifts_route (route_id, status),
  CONSTRAINT fk_shifts_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_shifts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_shifts_route FOREIGN KEY (route_id) REFERENCES taxi_routes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taxi_locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_id INT UNSIGNED NOT NULL,
  driver_id INT UNSIGNED NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  heading DECIMAL(6,2) DEFAULT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_taxi_loc_shift (shift_id, recorded_at),
  CONSTRAINT fk_taxi_loc_shift FOREIGN KEY (shift_id) REFERENCES taxi_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taxi_waiting (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  passenger_id INT UNSIGNED NOT NULL,
  route_id INT UNSIGNED DEFAULT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  note VARCHAR(190) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_waiting_route (route_id, is_active),
  CONSTRAINT fk_waiting_passenger FOREIGN KEY (passenger_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_waiting_route FOREIGN KEY (route_id) REFERENCES taxi_routes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rides (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  passenger_id INT UNSIGNED NOT NULL,
  driver_id INT UNSIGNED DEFAULT NULL,
  vehicle_id INT UNSIGNED DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'REQUESTED',
  pickup_label VARCHAR(190) NOT NULL,
  dest_label VARCHAR(190) NOT NULL,
  pickup_lat DECIMAL(10,7) NOT NULL,
  pickup_lng DECIMAL(10,7) NOT NULL,
  dest_lat DECIMAL(10,7) NOT NULL,
  dest_lng DECIMAL(10,7) NOT NULL,
  distance_km DECIMAL(8,3) DEFAULT NULL,
  eta_minutes INT DEFAULT NULL,
  fare_total DECIMAL(10,2) DEFAULT NULL,
  driver_earnings DECIMAL(10,2) DEFAULT NULL,
  platform_commission DECIMAL(10,2) DEFAULT NULL,
  commission_percent DECIMAL(5,2) DEFAULT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'SZL',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  cancelled_by ENUM('passenger','driver','admin','system') DEFAULT NULL,
  cancel_reason VARCHAR(255) DEFAULT NULL,
  KEY idx_rides_passenger (passenger_id, requested_at),
  KEY idx_rides_driver (driver_id, status),
  KEY idx_rides_status (status),
  CONSTRAINT fk_rides_passenger FOREIGN KEY (passenger_id) REFERENCES users(id),
  CONSTRAINT fk_rides_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_rides_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ride_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  actor_user_id INT UNSIGNED DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rsh_ride (ride_id),
  CONSTRAINT fk_rsh_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  heading DECIMAL(6,2) DEFAULT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dl_driver (driver_id, recorded_at),
  CONSTRAINT fk_dl_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id INT UNSIGNED NOT NULL,
  rater_user_id INT UNSIGNED NOT NULL,
  ratee_user_id INT UNSIGNED NOT NULL,
  stars TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rating_ride_rater (ride_id, rater_user_id),
  CONSTRAINT fk_ratings_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rating_id INT UNSIGNED NOT NULL,
  ride_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reviews_rating (rating_id),
  CONSTRAINT fk_reviews_rating FOREIGN KEY (rating_id) REFERENCES ratings(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS saved_locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  address VARCHAR(190) DEFAULT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_saved_user (user_id),
  CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(255) NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload_json TEXT DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ride_id INT UNSIGNED NOT NULL,
  method ENUM('cash','later') NOT NULL DEFAULT 'cash',
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'SZL',
  status ENUM('recorded','pending') NOT NULL DEFAULT 'recorded',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_ride (ride_id),
  CONSTRAINT fk_payments_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS earnings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_id INT UNSIGNED NOT NULL,
  ride_id INT UNSIGNED NOT NULL,
  gross_fare DECIMAL(10,2) NOT NULL,
  commission DECIMAL(10,2) NOT NULL,
  net_amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'SZL',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_earnings_ride (ride_id),
  KEY idx_earn_driver (driver_id),
  CONSTRAINT fk_earn_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_earn_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NOT NULL,
  ride_id INT UNSIGNED DEFAULT NULL,
  subject VARCHAR(120) NOT NULL,
  details TEXT NOT NULL,
  status ENUM('open','reviewing','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reports_status (status),
  CONSTRAINT fk_reports_user FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tickets_user (user_id),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value) VALUES
  ('commission_percent', '0'),
  ('fare_base', '15'),
  ('fare_per_km', '8'),
  ('fare_per_min', '0.5'),
  ('currency', 'SZL'),
  ('nearby_driver_km', '8'),
  ('nearby_taxi_km', '12')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO taxi_routes (origin_name, destination_name, origin_lat, origin_lng, dest_lat, dest_lng, typical_duration_min) VALUES
  ('Manzini', 'Mbabane', -26.4951, 31.3800, -26.3054, 31.1367, 40),
  ('Mbabane', 'Manzini', -26.3054, 31.1367, -26.4951, 31.3800, 40),
  ('Manzini', 'Matsapha', -26.4951, 31.3800, -26.5280, 31.3070, 15),
  ('Matsapha', 'Manzini', -26.5280, 31.3070, -26.4951, 31.3800, 15),
  ('Mbabane', 'Ezulwini', -26.3054, 31.1367, -26.4100, 31.1750, 20),
  ('Ezulwini', 'Mbabane', -26.4100, 31.1750, -26.3054, 31.1367, 20),
  ('Manzini', 'Nhlangano', -26.4951, 31.3800, -27.1167, 31.2000, 90),
  ('Mbabane', 'Piggs Peak', -26.3054, 31.1367, -25.9650, 31.2470, 70),
  ('Manzini', 'Siteki', -26.4951, 31.3800, -26.4500, 31.9500, 75),
  ('Manzini', 'Big Bend', -26.4951, 31.3800, -26.8167, 31.9333, 80),
  ('Mbabane', 'Lobamba', -26.3054, 31.1367, -26.4465, 31.2060, 25),
  ('Manzini', 'Malkerns', -26.4951, 31.3800, -26.5333, 31.1833, 25)
ON DUPLICATE KEY UPDATE origin_name = origin_name;
