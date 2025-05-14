from flask import Flask, request
import subprocess

app = Flask(__name__)

@app.route('/webhook', methods=['POST'])
def webhook():
    if not request.is_json:
        return "Invalid payload", 400

    payload = request.get_json()  # Get the JSON payload from GitHub

    # Check if the pushed branch is 'main'
    if payload.get('ref') == 'refs/heads/main':
        print("✅ Push to main branch detected")

        try:
            # Pull the latest code and capture output
            result = subprocess.run(
                ["git", "pull"],
                cwd="/home/ehbstudent/frontend",
                check=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True
            )
            print("🌀 Git pull successful")
            print(result.stdout)  # Show git pull output

            # Restart the Docker container
            subprocess.run(
                ["docker-compose", "restart", "frontend_wordpress"],
                cwd="/home/ehbstudent/frontend",
                check=True
            )
            print("🚀 Docker container restarted")

            return "Deployment successful", 200

        except subprocess.CalledProcessError as e:
            print("❌ Deployment failed:", e)
            print("❌ Error Output:", e.stderr)
            return "Deployment failed", 500
    else:
        print(f"❌ Push to {payload.get('ref')} ignored. Not the main branch.")
        return "Ignored, not a push to main branch", 200

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=30012)
# This script listens for GitHub webhook events and triggers a deployment process.
# It checks if the pushed branch is 'main', pulls the latest code, and restarts the Docker container.