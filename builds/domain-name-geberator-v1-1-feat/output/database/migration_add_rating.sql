-- Migration: Add rating column to generated_domains table
-- Run this on existing databases to add the marketability rating feature

USE domain_generator;

-- Add rating column if it doesn't exist
ALTER TABLE generated_domains
ADD COLUMN IF NOT EXISTS rating TINYINT UNSIGNED DEFAULT 5 COMMENT 'Marketability rating 1-10'
AFTER domain;

-- Update existing rows with default rating of 5
UPDATE generated_domains
SET rating = 5
WHERE rating IS NULL;
