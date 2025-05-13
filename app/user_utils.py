# app/user_utils.py

import re

def validate_user(user):
    errors = []

    # Check first name
    if not user.get("first_name") or not user["first_name"].isalpha():
        errors.append("Invalid first name")

    # Check last name
    if not user.get("last_name") or not user["last_name"].isalpha():
        errors.append("Invalid last name")

    # Check phone number (must be 8–15 digits, with optional +)
    phone = user.get("phone_number")
    if not phone or not re.fullmatch(r"\+?[0-9]{8,15}", phone):
        errors.append("Invalid phone number")

    return errors
