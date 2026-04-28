-- =====================================================
-- HALAL CERTIFYING BODY SYSTEM DATABASE
-- Based on Halal Institute of Development Philippines
-- =====================================================

CREATE DATABASE IF NOT EXISTS halal_system;
USE halal_system;

-- =====================================================
-- CORE USER & ROLE TABLES
-- =====================================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    role_category ENUM('customer', 'business', 'hcb_employee', 'lab_employee') NOT NULL,
    description TEXT,
    requires_approval TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (role_name, role_category, description, requires_approval) VALUES
('Customer', 'customer', 'End customer who orders food from halal-certified restaurants', 0),
('Business Owner', 'business', 'Halal business owner applying for halal certification', 1),
('Evaluator', 'hcb_employee', 'Evaluates and verifies applications and creates inspection schedules', 1),
('Auditor - Technical', 'hcb_employee', 'Conducts technical audits and inspections', 1),
('Auditor - Shariah', 'hcb_employee', 'Conducts Shariah compliance audits and inspections', 1),
('Impartial Committee', 'hcb_employee', 'Reviews potential decisions and evidence for certification', 1),
('Decision Committee', 'hcb_employee', 'Makes final decisions on halal certification grants', 1),
('President', 'hcb_employee', 'Awards halal certificates and logos to certified businesses', 1),
('Receiving Officer', 'lab_employee', 'Receives and manages laboratory analysis requirements', 1),
('Laboratory Analyst', 'lab_employee', 'Conducts laboratory analysis on submitted samples', 1);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    avatar VARCHAR(500),
    password VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    role_id INT DEFAULT NULL,
    role_status ENUM('pending', 'approved', 'rejected', 'none') DEFAULT 'none',
    is_active TINYINT(1) DEFAULT 1,
    email_verified TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    application_letter TEXT,
    resume_path VARCHAR(500),
    credentials_path VARCHAR(500),
    additional_docs TEXT,
    business_name VARCHAR(255),
    business_address TEXT,
    business_type VARCHAR(100),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    review_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- CERTIFICATION WORKFLOW TABLES
-- =====================================================

-- Step 1: Letter of Intent
CREATE TABLE letter_of_intent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_owner_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    company_address TEXT NOT NULL,
    company_info TEXT NOT NULL,
    date_of_intent DATE NULL,
    menu_list TEXT,
    contact_person VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    letter_content TEXT NOT NULL,
    supporting_documents TEXT,
    status ENUM('draft', 'submitted', 'under_review', 'verified', 'returned', 'rejected') DEFAULT 'draft',
    remarks TEXT,
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2: LOI Verification (Evaluator)
CREATE TABLE loi_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loi_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    verification_status ENUM('pending', 'verified', 'returned') DEFAULT 'pending',
    feedback TEXT,
    verified_letter_path VARCHAR(500),
    notify_inspection TINYINT(1) DEFAULT 0,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loi_id) REFERENCES letter_of_intent(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2b: LOI Requirements sent by evaluator
CREATE TABLE IF NOT EXISTS loi_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loi_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    requirements TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loi_id) REFERENCES letter_of_intent(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2c: Per-requirement file uploads by business owner
CREATE TABLE IF NOT EXISTS application_requirement_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    requirement_label VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 3: HDP Application Requirements
CREATE TABLE hdp_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loi_id INT NOT NULL,
    business_owner_id INT NOT NULL,
    application_form_path VARCHAR(500),
    supporting_papers TEXT,
    request_form_path VARCHAR(500),
    reviews_ratings TEXT,
    laboratory_report_path VARCHAR(500),
    requirements_complete TINYINT(1) DEFAULT 0,
    status ENUM('draft', 'submitted', 'under_review', 'verified', 'incomplete', 'approved') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loi_id) REFERENCES letter_of_intent(id) ON DELETE CASCADE,
    FOREIGN KEY (business_owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 4: Application Verification (Evaluator)
CREATE TABLE application_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    verification_status ENUM('pending', 'verified', 'returned', 'incomplete') DEFAULT 'pending',
    feedback TEXT,
    verified_requirements TEXT,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 5: Terms of Reference & Registration Agreement
CREATE TABLE terms_of_reference (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    tor_content TEXT NOT NULL,
    registration_agreement TEXT,
    payment_for_inspection DECIMAL(10,2) DEFAULT 0.00,
    inspection_date DATE,
    inspection_fees DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('draft', 'sent', 'accepted', 'rejected') DEFAULT 'draft',
    business_response ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    business_remarks TEXT,
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 6: Inspection Schedules
CREATE TABLE inspection_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    evaluator_id INT NOT NULL,
    tor_id INT,
    schedule_date DATE NOT NULL,
    schedule_time TIME,
    location TEXT,
    inspectors TEXT,
    inspection_fees DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tor_id) REFERENCES terms_of_reference(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 7: Inspections (Conducted by Auditors)
CREATE TABLE inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    application_id INT NOT NULL,
    auditor_technical_id INT,
    auditor_shariah_id INT,
    audit_findings TEXT,
    conformity_status ENUM('conforming', 'non_conforming', 'partial', 'pending') DEFAULT 'pending',
    remarks TEXT,
    inspection_date DATE,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES inspection_schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (auditor_technical_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (auditor_shariah_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 8: Non-Conformance Reports (NCR)
CREATE TABLE ncr_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT NOT NULL,
    application_id INT NOT NULL,
    ncr_number VARCHAR(50) UNIQUE,
    finding_details TEXT NOT NULL,
    category ENUM('major', 'minor', 'observation') DEFAULT 'minor',
    corrective_action_required TEXT,
    corrective_action_taken TEXT,
    deadline DATE,
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 9-11: Laboratory
CREATE TABLE laboratory_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    business_owner_id INT NOT NULL,
    request_number VARCHAR(50),
    sample_description TEXT NOT NULL,
    analysis_requirements TEXT NOT NULL,
    payment_testing_fee DECIMAL(10,2) DEFAULT 0.00,
    payment_status ENUM('unpaid', 'paid', 'waived') DEFAULT 'unpaid',
    status ENUM('submitted', 'received', 'testing', 'completed', 'rejected') DEFAULT 'submitted',
    received_by INT DEFAULT NULL,
    received_at TIMESTAMP NULL,
    receiving_notes TEXT,
    analyst_id INT DEFAULT NULL,
    testing_started_at TIMESTAMP NULL,
    test_result ENUM('pass', 'fail', 'inconclusive') DEFAULT NULL,
    analysis_details TEXT,
    halal_status ENUM('halal', 'haram', 'mushbooh') DEFAULT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (business_owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE laboratory_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    analyst_id INT NOT NULL,
    analysis_method TEXT,
    analysis_results TEXT,
    conclusion TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES laboratory_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE laboratory_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    laboratory_request_id INT,
    application_id INT NOT NULL,
    analyst_id INT NOT NULL,
    report_number VARCHAR(50) UNIQUE,
    report_content TEXT NOT NULL,
    report_file_path VARCHAR(500),
    halal_status ENUM('halal', 'haram', 'mushbooh') DEFAULT NULL,
    status ENUM('draft', 'finalized', 'sent') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (laboratory_request_id) REFERENCES laboratory_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 12: Corrective Action Reports
CREATE TABLE corrective_action_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    laboratory_report_id INT,
    inspection_id INT,
    report_content TEXT NOT NULL,
    corrective_actions TEXT,
    status ENUM('draft', 'submitted', 'reviewed', 'approved') DEFAULT 'draft',
    prepared_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (laboratory_report_id) REFERENCES laboratory_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE SET NULL,
    FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 13: Potential Decision Review (Impartial Committee)
CREATE TABLE potential_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    corrective_action_id INT,
    committee_member_id INT NOT NULL,
    evidence_summary TEXT,
    review_notes TEXT,
    decision ENUM('recommend_approve', 'recommend_reject', 'need_more_info', 'pending') DEFAULT 'pending',
    notify_payment TINYINT(1) DEFAULT 0,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (corrective_action_id) REFERENCES corrective_action_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (committee_member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 14: Final Decision (Decision Committee)
CREATE TABLE final_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    potential_decision_id INT,
    committee_member_id INT NOT NULL,
    decision ENUM('approved', 'rejected', 'deferred', 'pending') DEFAULT 'pending',
    decision_notes TEXT,
    decided_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (potential_decision_id) REFERENCES potential_decisions(id) ON DELETE SET NULL,
    FOREIGN KEY (committee_member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 15-16: Halal Certificates
CREATE TABLE halal_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    final_decision_id INT NOT NULL,
    certificate_number VARCHAR(100) UNIQUE NOT NULL,
    business_name VARCHAR(255) NOT NULL,
    business_address TEXT,
    certificate_type VARCHAR(100),
    issue_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    halal_logo_path VARCHAR(500),
    certificate_file_path VARCHAR(500),
    decorated_by INT,
    awarded_by INT,
    status ENUM('prepared', 'decorated', 'awarded', 'active', 'expired', 'revoked', 'suspended') DEFAULT 'prepared',
    payment_amount DECIMAL(10,2) DEFAULT 0.00,
    payment_status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES hdp_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (final_decision_id) REFERENCES final_decisions(id) ON DELETE CASCADE,
    FOREIGN KEY (decorated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- CUSTOMER-FACING TABLES
-- =====================================================

-- Step 17: Halal Restaurants (listed after certification)
CREATE TABLE halal_restaurants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_owner_id INT NOT NULL,
    certificate_id INT,
    restaurant_name VARCHAR(255) NOT NULL,
    description TEXT,
    address TEXT NOT NULL,
    city VARCHAR(100),
    province VARCHAR(100),
    phone VARCHAR(50),
    email VARCHAR(255),
    operating_hours TEXT,
    cuisine_type VARCHAR(100),
    image_path VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    avg_rating DECIMAL(3,2) DEFAULT 0.00,
    total_reviews INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (certificate_id) REFERENCES halal_certificates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    category VARCHAR(100),
    image_path VARCHAR(500),
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES halal_restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 18: Orders
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    items_json TEXT NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    delivery_fee DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address TEXT,
    payment_method ENUM('cash', 'gcash', 'bank_transfer', 'card') DEFAULT 'cash',
    payment_status ENUM('pending', 'paid', 'refunded') DEFAULT 'pending',
    order_status ENUM('pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (restaurant_id) REFERENCES halal_restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 19: Reviews & Ratings
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    order_id INT,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    food_rating INT CHECK (food_rating >= 1 AND food_rating <= 5),
    service_rating INT CHECK (service_rating >= 1 AND service_rating <= 5),
    comment TEXT,
    response TEXT,
    response_by INT,
    response_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (restaurant_id) REFERENCES halal_restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (response_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- SYSTEM TABLES
-- =====================================================

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error', 'action_required') DEFAULT 'info',
    link VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    module VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reference_type ENUM('inspection', 'laboratory', 'certification', 'order') NOT NULL,
    reference_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    transaction_reference VARCHAR(255),
    receipt_path VARCHAR(500),
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    verified_by INT,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('site_name', 'Halal Institute of Development Philippines', 'System name'),
('site_tagline', 'Halal Certification Body', 'Site tagline'),
('certification_validity_years', '2', 'Number of years a halal certificate is valid'),
('inspection_base_fee', '5000.00', 'Base inspection fee in PHP'),
('laboratory_base_fee', '3000.00', 'Base laboratory analysis fee in PHP'),
('certification_fee', '10000.00', 'Halal certification fee in PHP'),
('google_client_id', '', 'Google OAuth Client ID'),
('google_client_secret', '', 'Google OAuth Client Secret');

-- Create indexes for performance
CREATE INDEX idx_users_google_id ON users(google_id);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role_id);
CREATE INDEX idx_loi_owner ON letter_of_intent(business_owner_id);
CREATE INDEX idx_loi_status ON letter_of_intent(status);
CREATE INDEX idx_hdp_owner ON hdp_applications(business_owner_id);
CREATE INDEX idx_hdp_status ON hdp_applications(status);
CREATE INDEX idx_inspections_schedule ON inspections(schedule_id);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_restaurant ON orders(restaurant_id);
CREATE INDEX idx_reviews_restaurant ON reviews(restaurant_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_notifications_read ON notifications(is_read);
