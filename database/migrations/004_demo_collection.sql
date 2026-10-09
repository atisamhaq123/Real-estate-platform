CREATE TABLE demo_listing_seeds (
    seed_key VARCHAR(100) PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id)
) ENGINE=InnoDB;
