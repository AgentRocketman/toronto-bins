#!/bin/bash

# CurbIn Backend API Test Script
# Tests all endpoints with sample data

BASE_URL="http://localhost:3000"

echo "🧪 CurbIn Backend API Test Suite"
echo "=================================="
echo ""

# Color codes
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Health Check
echo -e "${BLUE}Test 1: Health Check${NC}"
echo "GET $BASE_URL/api/health"
curl -s -X GET "$BASE_URL/api/health" | jq '.' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

# Test 2: Create Test Data
echo -e "${BLUE}Test 2: Create Test Data (8 service stops)${NC}"
echo "GET $BASE_URL/api/test-airtable"
curl -s -X GET "$BASE_URL/api/test-airtable" | jq '.message' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

# Test 3: Get All Services
echo -e "${BLUE}Test 3: Get All Services${NC}"
echo "GET $BASE_URL/api/services"
curl -s -X GET "$BASE_URL/api/services" | jq '.count' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

# Test 4: Save Service Stop (without image)
echo -e "${BLUE}Test 4: Save Service Stop${NC}"
echo "POST $BASE_URL/api/save-service"
curl -s -X POST "$BASE_URL/api/save-service" \
  -H "Content-Type: application/json" \
  -d '{
    "id": "test-stop-001",
    "address": "999 Test Ave, Toronto, ON",
    "type": "Residential",
    "date": "2024-06-13",
    "completed": false,
    "imageUrl": "",
    "workerName": "Test Worker"
  }' | jq '.recordId' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

# Test 5: Optimize Route
echo -e "${BLUE}Test 5: Optimize Route${NC}"
echo "POST $BASE_URL/api/optimize-route"
curl -s -X POST "$BASE_URL/api/optimize-route" \
  -H "Content-Type: application/json" \
  -d '{
    "stops": [
      { "id": "stop-001", "address": "123 King St W", "lat": 43.6426, "lng": -79.3957 },
      { "id": "stop-002", "address": "456 Queen St W", "lat": 43.6452, "lng": -79.4003 },
      { "id": "stop-003", "address": "789 Bay St", "lat": 43.6629, "lng": -79.3957 }
    ]
  }' | jq '.success' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

# Test 6: Test Invalid Request
echo -e "${BLUE}Test 6: Test Error Handling (invalid request)${NC}"
echo "POST $BASE_URL/api/save-service (missing required fields)"
curl -s -X POST "$BASE_URL/api/save-service" \
  -H "Content-Type: application/json" \
  -d '{"id": "test"}' | jq '.error' && echo -e "${GREEN}✓ Passed${NC}\n" || echo -e "${YELLOW}✗ Failed${NC}\n"

echo -e "${BLUE}=================================="
echo "Test suite completed!${NC}"
