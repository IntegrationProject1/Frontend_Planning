# tests/test_user_utils.py

from app.user_utils import validate_user

def test_valid_user():
    user = {
        "first_name": "Weiam",
        "last_name": "Almahnash",
        "phone_number": "+32470123456"
    }
    assert validate_user(user) == []

def test_missing_first_name():
    user = {
        "first_name": "",
        "last_name": "Smith",
        "phone_number": "0498123456"
    }
    assert "Invalid first name" in validate_user(user)

def test_invalid_last_name():
    user = {
        "first_name": "Alice",
        "last_name": "123",
        "phone_number": "0498123456"
    }
    assert "Invalid last name" in validate_user(user)

def test_invalid_phone_number():
    user = {
        "first_name": "John",
        "last_name": "Doe",
        "phone_number": "abcde12345"
    }
    assert "Invalid phone number" in validate_user(user)
