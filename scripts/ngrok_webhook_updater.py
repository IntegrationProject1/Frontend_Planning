import requests
import subprocess
import time
import platform
import os
from dotenv import load_dotenv 

# ====== CONFIGURATION ======
load_dotenv() 

GITHUB_TOKEN = os.getenv("GITHUB_TOKEN")
REPO_OWNER = "IntegrationProject1"
REPO_NAME = "Frontend"
WEBHOOK_ID = 544925591  # ngrok webhook
PORT = 30012
# ===========================

# 🔍 Determine ngrok command based on OS
if platform.system() == "Windows":
    ngrok_cmd = [r"C:\Users\Weiam\Downloads\ngrok.exe", "http", str(PORT)]
else:
    ngrok_cmd = ["ngrok", "http", str(PORT)]

# Step 1: Start ngrok
print("🔄 Starting ngrok...")
ngrok_process = subprocess.Popen(ngrok_cmd)
time.sleep(3)  # Wait for ngrok to initialize

# Step 2: Get public URL from ngrok
try:
    response = requests.get("http://localhost:4040/api/tunnels")
    tunnels = response.json()["tunnels"]
    public_url = next(t["public_url"] for t in tunnels if t["proto"] == "https")
    print(f"✅ Ngrok URL: {public_url}")
except Exception as e:
    print("❌ Failed to get ngrok URL:", e)
    ngrok_process.kill()
    exit(1)

# Step 3: Update GitHub webhook with new ngrok URL
headers = {
    "Authorization": f"Bearer {GITHUB_TOKEN}",
    "Accept": "application/vnd.github+json"
}

webhook_url = f"https://api.github.com/repos/{REPO_OWNER}/{REPO_NAME}/hooks/{WEBHOOK_ID}"
payload = {
    "config": {
        "url": f"{public_url}/webhook",
        "content_type": "json"
    }
}

print("🔁 Updating GitHub Webhook...")
response = requests.patch(webhook_url, headers=headers, json=payload)

if response.status_code == 200:
    print("✅ Webhook updated successfully!")
else:
    print(f"❌ Failed to update webhook: {response.status_code} → {response.text}")
