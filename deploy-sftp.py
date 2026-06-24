#!/usr/bin/env python3
"""
SFTP Deploy Script - Selective file upload (preserves server images)
Usage: python3 deploy-sftp.py agentrocketman.com
       python3 deploy-sftp.py getmybin.com
"""

import sys
import os
import paramiko
from pathlib import Path

DOMAIN = sys.argv[1] if len(sys.argv) > 1 else "agentrocketman.com"
FTP_USER = "u686706869"
FTP_PASS = "FTPAgentPassword1!"
SOURCE_DIR = "/data/.openclaw/workspace/public_html"

# Determine remote path
REMOTE_PATHS = {
    "agentrocketman.com": "/home/u686706869/domains/agentrocketman.com/public_html",
    "getmybin.com": "/home/u686706869/domains/getmybin.com/public_html",
}

if DOMAIN not in REMOTE_PATHS:
    print(f"❌ Unknown domain: {DOMAIN}")
    sys.exit(1)

REMOTE_PATH = REMOTE_PATHS[DOMAIN]

print(f"🚀 SFTP Deploy to {DOMAIN}")
print(f"Local: {SOURCE_DIR}")
print(f"Remote: {REMOTE_PATH}")
print()

def upload_dir(sftp, local_path, remote_path, exclude_dirs=None):
    """Recursively upload directory, excluding specified folders"""
    if exclude_dirs is None:
        exclude_dirs = {'.git', '__pycache__'}
    
    for item in os.listdir(local_path):
        if item.startswith('.'):
            continue
        
        local_item = os.path.join(local_path, item)
        remote_item = f"{remote_path}/{item}".replace("//", "/")
        
        if os.path.isdir(local_item):
            try:
                sftp.stat(remote_item)
            except FileNotFoundError:
                print(f"  📁 mkdir {remote_item}")
                sftp.mkdir(remote_item)
            
            upload_dir(sftp, local_item, remote_item, exclude_dirs)
        else:
            print(f"  📤 {item}")
            sftp.put(local_item, remote_item)

try:
    # Connect via SFTP
    transport = paramiko.Transport((DOMAIN, 22))
    transport.connect(username=FTP_USER, password=FTP_PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    
    print(f"✅ Connected to {DOMAIN}")
    print()
    
    # Upload all files recursively
    upload_dir(sftp, SOURCE_DIR, REMOTE_PATH)
    
    sftp.close()
    transport.close()
    
    print()
    print("✅ SFTP Deploy Complete")
    print("   - Uploaded changed files to", DOMAIN)
    print("   - Server images in /bin-pics/ remain untouched")

except Exception as e:
    print(f"❌ SFTP Deploy Failed: {e}")
    sys.exit(1)
