-- Users Table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Members Table
CREATE TABLE IF NOT EXISTS members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  fellowship VARCHAR(100),
  concession ENUM('Yes','No') DEFAULT 'No',
  site_fee_status ENUM('Paid','Unpaid','Overdue','Exempt','Unknown') DEFAULT 'Unknown',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sites Table
CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_number VARCHAR(20) UNIQUE,
  section VARCHAR(50),
  site_type VARCHAR(50),
  status ENUM('Available','Allocated','Inactive') DEFAULT 'Available'
);

-- Site Allocations Table
CREATE TABLE IF NOT EXISTS site_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT,
  member_id INT,
  start_date DATE,
  end_date DATE NULL,
  is_current BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (site_id) REFERENCES sites(id),
  FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Site Fee Accounts Table
CREATE TABLE IF NOT EXISTS site_fee_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT,
  paid_until DATE,
  status ENUM('Paid','Overdue','Exempt'),
  FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Camps Table
CREATE TABLE IF NOT EXISTS camps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  year INT,
  start_date DATE,
  end_date DATE,
  on_peak_start DATE,
  on_peak_end DATE,
  status ENUM('Draft','Active','Closed') DEFAULT 'Draft'
);

-- Camp Rates Table
CREATE TABLE IF NOT EXISTS camp_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camp_id INT,
  category VARCHAR(50), -- e.g. "Daily Rate", "2 Night Camp", "Site Fees"
  item VARCHAR(100),    -- e.g. "Breakfast", "Camp Rate", "Powered Site"
  user_type VARCHAR(50), -- e.g. "Adult", "Concession", "Child", "Family"
  amount DECIMAL(10,2),
  FOREIGN KEY (camp_id) REFERENCES camps(id)
);

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT,
  camp_id INT NULL,
  site_id INT NULL,
  payment_date DATETIME,
  camp_fee DECIMAL(10,2),
  site_fee DECIMAL(10,2),
  prepaid_applied DECIMAL(10,2),
  other_amount DECIMAL(10,2),
  total DECIMAL(10,2),
  headcount INT,
  notes TEXT,
  FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Payment Tenders Table
CREATE TABLE IF NOT EXISTS payment_tenders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT,
  method ENUM('EFTPOS','Cash','Cheque'),
  amount DECIMAL(10,2),
  reference VARCHAR(100),
  FOREIGN KEY (payment_id) REFERENCES payments(id)
);

-- Prepayments Table
CREATE TABLE IF NOT EXISTS prepayments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camp_id INT,
  imported_name VARCHAR(255),
  first_name VARCHAR(255),
  last_name VARCHAR(255),
  amount DECIMAL(10,2),
  transaction_id VARCHAR(255),
  date VARCHAR(50), -- Kept as string to preserve CSV format until parsing
  matched_member_id INT NULL,
  original_data TEXT, -- JSON dump of the row
  status ENUM('Matched','Needs Review','Unmatched') DEFAULT 'Unmatched',
  FOREIGN KEY (matched_member_id) REFERENCES members(id)
);
