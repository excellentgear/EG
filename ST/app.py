from flask import Flask, jsonify
from flask_cors import CORS
import subprocess
import os

app = Flask(__name__)
CORS(app)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

@app.route('/api/run-import')
def run_import():
    try:
        # 強制指定子程序環境的編碼為 utf-8，避免 Windows CMD 繁體中文環境的編碼衝突
        my_env = os.environ.copy()
        my_env["PYTHONIOENCODING"] = "utf-8"
        
        # errors='ignore' 確保遇到無法解析的字元直接忽略，不會當機中斷
        result = subprocess.run(
            ['python', 'excel_import.py'], 
            cwd=BASE_DIR, 
            capture_output=True, 
            text=True, 
            encoding='utf-8', 
            errors='ignore',
            env=my_env
        )
        
        if result.returncode == 0:
            return jsonify({"status": "success", "message": result.stdout})
        else:
            return jsonify({"status": "error", "message": result.stderr})
            
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)})

if __name__ == '__main__':
    print("EG System API is now running on port 5000...")
    print("Press CTRL+C to quit.")
    # 允許同一個區域網路內的電腦存取 5000 port
    app.run(host='0.0.0.0', port=5000)
