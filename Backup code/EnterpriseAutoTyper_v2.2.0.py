"""
NBM Enterprise AutoTyper - v2.2.0
Developed By - ANIL KUMAR
Modified By  - JSSBPO System (v3.0 Upgrade)

Changes in v2.2.0:
- Report to Admin full module (F4 shortcut)
- First QC / Second QC naming system
- Reported records yellow highlight in queue
- Existing reports display on record select
- Reported filter checkbox added
- New API: submit_report_to_admin, get_all_reports, get_reports_for_record
- Mark Report Solved (Admin/Supervisor)
- F1=Start, F2=Save & Next, F3=Pause, F4=Report to Admin, ESC=Stop
"""

import customtkinter as ctk
from tkinter import filedialog, messagebox, ttk
import pandas as pd
import pyautogui
import time
import threading
import os
import json
import csv
import keyboard
import urllib.request
import urllib.parse
import http.cookiejar
from datetime import datetime
import zipfile
import ssl
import hashlib
import logging

# ==========================================
# 1. CONFIGURATION & LOGGING SETUP
# ==========================================

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('autotyper.log', encoding='utf-8'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

ctk.set_appearance_mode("Light")
ctk.set_default_color_theme("blue")

DEFAULT_API_URL  = "https://jssbpo.in/api.php"
SHARED_BASE_FOLDER = r"C:\DataNBM"
USER_DATA_DIR  = os.path.join(SHARED_BASE_FOLDER, "user_logs")
BACKUP_DIR     = os.path.join(SHARED_BASE_FOLDER, "backups")
CONFIG_FILE    = os.path.join(SHARED_BASE_FOLDER, "config.json")
BATCH_QUEUE_FILE = os.path.join(SHARED_BASE_FOLDER, "pending_batch.json")

DEVELOPER_TEXT = "Autotyper - Developed By - ANIL KUMAR, 8348162724"
APP_VERSION    = "2.2.0"

# Colors
CLASSIC_BG       = "#e9e9e9"
CLASSIC_ACCENT   = "#3a71b1"
CLASSIC_HOVER    = "#4a86c6"
DONE_COLOR       = "#27ae60"          # Green  - 2nd QC Pending (was Done)
QC_DONE_COLOR    = "#0d6efd"          # Blue   - 2nd QC Done
COMPLETED_COLOR  = "#dc3545"          # Red    - Final Completed
TEXT_COLOR       = "#333333"
ERROR_COLOR      = "#c0392b"
WARNING_COLOR    = "#f39c12"
REPORTED_COLOR   = "#fd7e14"          # Orange - Reported
PENDING_COLOR    = "#6c757d"          # Gray   - 1st QC Pending

for folder in [SHARED_BASE_FOLDER, USER_DATA_DIR, BACKUP_DIR]:
    if not os.path.exists(folder):
        try:
            os.makedirs(folder)
        except Exception as e:
            print(f"Folder create error {folder}: {e}")

# ==========================================
# 2. SIMPLE CONFIG MANAGER
# ==========================================

class SimpleConfig:
    def __init__(self):
        self.config = {
            'api_url': DEFAULT_API_URL,
            'verify_ssl': False,
            'batch_size': 5,
            'admin_hash': '',
            'sync_interval': 25,
            'realtime_mode': True
        }
        self.load()

    def load(self):
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r') as f:
                    saved = json.load(f)
                    self.config.update(saved)
                    if '/1/' in self.config.get('api_url','') or '/2/' in self.config.get('api_url','') or '/3/' in self.config.get('api_url',''):
                        self.config['api_url'] = DEFAULT_API_URL
                        self.save()
            except:
                pass

    def save(self):
        try:
            with open(CONFIG_FILE, 'w') as f:
                json.dump(self.config, f, indent=2)
        except Exception as e:
            logger.error(f"Config save error: {e}")

    def get(self, key, default=None):
        return self.config.get(key, default)

    def set(self, key, value):
        self.config[key] = value
        self.save()

config = SimpleConfig()

# ==========================================
# 3. BATCH QUEUE
# ==========================================

class SimpleBatchQueue:
    def __init__(self):
        self.queue = []
        self._load()

    def _load(self):
        if os.path.exists(BATCH_QUEUE_FILE):
            try:
                with open(BATCH_QUEUE_FILE, 'r') as f:
                    self.queue = json.load(f).get('items', [])
            except:
                self.queue = []

    def _save(self):
        try:
            with open(BATCH_QUEUE_FILE, 'w') as f:
                json.dump({'items': self.queue}, f)
        except:
            pass

    def add(self, item):
        if item not in self.queue:
            self.queue.append(item)
            self._save()

    def get_all(self):    return self.queue[:]
    def clear(self):      self.queue = []; self._save()
    def remove_items(self, items):
        for item in items:
            if item in self.queue: self.queue.remove(item)
        self._save()
    def size(self):       return len(self.queue)

# ==========================================
# 4. API CLIENT
# ==========================================

class SimpleAPIClient:
    def __init__(self):
        self.api_url    = config.get('api_url', DEFAULT_API_URL)
        self.cookie_jar = http.cookiejar.CookieJar()
        self.ssl_context = ssl._create_unverified_context()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookie_jar),
            urllib.request.HTTPSHandler(context=self.ssl_context)
        )
        urllib.request.install_opener(self.opener)

    def update_url(self, new_url):
        self.api_url = new_url
        config.set('api_url', new_url)
        logger.info(f"API URL updated: {new_url}")

    def call(self, data_dict, timeout=15):
        try:
            data = urllib.parse.urlencode(data_dict).encode('utf-8')
            req  = urllib.request.Request(
                self.api_url, data=data,
                headers={
                    'User-Agent': f'NBM-AutoTyper/{APP_VERSION}',
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            )
            with self.opener.open(req, timeout=timeout) as response:
                raw = response.read().decode('utf-8', errors='ignore').strip()
                if raw and not raw.startswith('{') and '{' in raw:
                    raw = raw[raw.find('{') : raw.rfind('}')+1]
                if not raw:
                    return None
                result = json.loads(raw)
                if result.get('status') != 'success':
                    logger.warning(f"API: {result}")
                return result
        except Exception as e:
            logger.error(f"API Error: {e}")
            return None

# ==========================================
# 5. MAIN APPLICATION CLASS
# ==========================================

class EnterpriseAutoTyper:
    def __init__(self, root):
        self.root = root
        self.root.title(f"NBM Enterprise AutoTyper v{APP_VERSION}")
        self.root.geometry("370x760")
        self.root.configure(fg_color=CLASSIC_BG)

        self.api         = SimpleAPIClient()
        self.batch_queue = SimpleBatchQueue()

        if not config.get('admin_hash'):
            config.set('admin_hash', hashlib.sha256("Anil@123".encode()).hexdigest())

        self.root.attributes('-topmost', True)
        self.root.attributes('-toolwindow', True)
        self._keep_on_top()

        # State Variables
        self.current_user      = None
        self.df                = None
        self.current_index     = 0
        self.is_running        = False
        self.is_paused         = False
        self.waiting_for_next  = False
        self.row_status        = []
        self.start_time        = 0
        self.session_rows_completed = 0
        self.row_times         = []
        self.backup_timer_id   = None
        self.sync_timer_id     = None
        self.current_file_path = "Server_Data"
        self.failed_records    = []
        self.is_saving         = False

        # Filter Variables
        self.filter_done      = True
        self.filter_qc_done   = True
        self.filter_completed = True
        self.filter_reported  = True

        # ─── Report to Admin ───
        # Dict: { record_no: [ {id, header_name, issue_details, reported_by, status, ...} ] }
        self.reported_records  = {}
        self.admin_replies     = {}   # {ce_id: {record_no, error_field, admin_remark, ...}}
        self.shown_reply_ids   = set()
        self.qc_enabled        = True   # Track QC system state

        self._setup_fonts()
        self._setup_shortcuts()
        self.show_welcome_screen()

    # ──────────────────────────────────────────
    # KEEP ON TOP & HELPERS
    # ──────────────────────────────────────────
    def _keep_on_top(self):
        try:
            self.root.lift()
            self.root.attributes('-topmost', True)
        except:
            pass
        self.root.after(500, self._keep_on_top)

    def clear_screen(self):
        for w in self.root.winfo_children():
            w.destroy()

    def _setup_fonts(self):
        self.header_font      = ctk.CTkFont(family="Segoe UI", size=16, weight="bold")
        self.sub_header_font  = ctk.CTkFont(family="Segoe UI", size=12, weight="bold")
        self.timer_font       = ctk.CTkFont(family="Segoe UI", size=36, weight="bold")
        self.status_font      = ctk.CTkFont(family="Segoe UI", size=13, weight="normal")
        self.action_btn_font  = ctk.CTkFont(family="Segoe UI", size=12, weight="bold")
        self.btn_font         = ctk.CTkFont(family="Segoe UI", size=11, weight="normal")
        self.data_entry_font  = ctk.CTkFont(family="Segoe UI", size=11, weight="normal")
        self.row_list_font    = ctk.CTkFont(family="Segoe UI", size=12, weight="bold")
        self.footer_font      = ctk.CTkFont(family="Segoe UI", size=9,  weight="bold")

    def _setup_shortcuts(self):
        try:
            keyboard.add_hotkey('f1',  lambda: self.root.after(0, self.start_typing))
            keyboard.add_hotkey('esc', lambda: self.root.after(0, self.stop_typing))
            keyboard.add_hotkey('f2',  lambda: self.root.after(0, self.next_row))
            keyboard.add_hotkey('f3',  lambda: self.root.after(0, self.toggle_pause))
            keyboard.add_hotkey('f4',  lambda: self.root.after(0, self.show_report_dialog))   # NEW: Report
        except Exception as e:
            logger.warning(f"Shortcut error: {e}")

    def add_footer(self):
        footer = ctk.CTkFrame(self.root, height=25, fg_color="#dcdcdc", corner_radius=0)
        footer.pack(side="bottom", fill="x")
        ctk.CTkLabel(footer, text=f"{DEVELOPER_TEXT} | v{APP_VERSION}", font=self.footer_font, text_color="gray").pack(pady=2)

    def format_time(self, seconds):
        try:
            m, s = int(seconds // 60), int(seconds % 60)
            return f"{m:02}:{s:02}"
        except:
            return "00:00"

    # ──────────────────────────────────────────
    # 6. LOGIN SCREENS
    # ──────────────────────────────────────────
    def show_welcome_screen(self):
        self.clear_screen()
        self.root.geometry("340x570")

        frame = ctk.CTkFrame(self.root, fg_color="transparent")
        frame.pack(fill="both", expand=True, padx=20, pady=20)

        ctk.CTkLabel(frame, text="NBM AUTOTYPER", font=("Segoe UI", 26, "bold"), text_color=CLASSIC_ACCENT).pack(pady=(50, 5))
        ctk.CTkLabel(frame, text=f"Enterprise Edition v{APP_VERSION}", font=("Segoe UI", 12), text_color="gray").pack(pady=(0, 3))
        ctk.CTkLabel(frame, text="QC System v3.0 ✅  |  Report to Admin 🔔", font=("Segoe UI", 10), text_color=QC_DONE_COLOR).pack(pady=(0, 5))
        ctk.CTkLabel(frame, text=f"Server: {config.get('api_url', DEFAULT_API_URL)}", font=("Segoe UI", 9), text_color="gray").pack(pady=(0, 25))

        card = ctk.CTkFrame(frame, corner_radius=10, border_width=1, fg_color="white", border_color="#cccccc")
        card.pack(pady=5, padx=10, fill="x")

        ctk.CTkButton(card, text="USER LOGIN", command=self.show_user_login,
                      height=45, font=self.action_btn_font, corner_radius=5,
                      fg_color=CLASSIC_ACCENT, hover_color=CLASSIC_HOVER).pack(pady=15, padx=20, fill="x")

        ctk.CTkButton(card, text="ADMIN LOGIN", command=self.show_admin_login,
                      height=45, font=self.action_btn_font, corner_radius=5,
                      fg_color="#34495e", hover_color="#2c3e50").pack(pady=(0, 15), padx=20, fill="x")

        # Shortcuts hint
        hint = ctk.CTkFrame(frame, fg_color="#f8f9fa", corner_radius=6, border_width=1, border_color="#dee2e6")
        hint.pack(fill="x", padx=5, pady=10)
        ctk.CTkLabel(hint, text="⌨️  F1=Start  F2=Save&Next  F3=Pause  F4=Report  ESC=Stop",
                     font=("Segoe UI", 9), text_color="gray").pack(pady=5)

        self.add_footer()

    def show_user_login(self):
        self.clear_screen()
        self.root.geometry("340x550")
        frame = ctk.CTkFrame(self.root, fg_color="transparent")
        frame.place(relx=0.5, rely=0.5, anchor="center")

        ctk.CTkLabel(frame, text="USER ACCESS", font=self.header_font, text_color=TEXT_COLOR).pack(pady=20)

        self.entry_username = ctk.CTkEntry(frame, placeholder_text="Username", width=220, height=40, font=self.data_entry_font)
        self.entry_username.pack(pady=10)

        self.entry_userpass = ctk.CTkEntry(frame, placeholder_text="Password", show="*", width=220, height=40, font=self.data_entry_font)
        self.entry_userpass.pack(pady=10)
        self.entry_userpass.bind('<Return>', lambda e: self.do_user_login())

        self.login_status = ctk.CTkLabel(frame, text="", font=("Segoe UI", 10), text_color=ERROR_COLOR)
        self.login_status.pack(pady=5)

        ctk.CTkButton(frame, text="Login & Start", command=self.do_user_login, width=220, height=40, font=self.btn_font, fg_color=CLASSIC_ACCENT).pack(pady=10)
        ctk.CTkButton(frame, text="← Go Back", command=self.show_welcome_screen, fg_color="transparent", text_color=TEXT_COLOR, font=self.footer_font).pack(pady=5)

        self.add_footer()

    def show_admin_login(self):
        self.clear_screen()
        frame = ctk.CTkFrame(self.root, fg_color="transparent")
        frame.place(relx=0.5, rely=0.5, anchor="center")

        ctk.CTkLabel(frame, text="ADMIN ACCESS", font=self.header_font, text_color=ERROR_COLOR).pack(pady=20)

        self.entry_admin_pass = ctk.CTkEntry(frame, placeholder_text="Admin Password", show="*", width=220, height=40)
        self.entry_admin_pass.pack(pady=10)
        self.entry_admin_pass.bind('<Return>', lambda e: self.do_admin_login())

        ctk.CTkButton(frame, text="Unlock Dashboard", command=self.do_admin_login, width=220, height=40, fg_color=ERROR_COLOR, hover_color="#a93226").pack(pady=20)
        ctk.CTkButton(frame, text="← Go Back", command=self.show_welcome_screen, fg_color="transparent", text_color=TEXT_COLOR).pack(pady=5)

        self.add_footer()

    def do_admin_login(self):
        entered = self.entry_admin_pass.get()
        if hashlib.sha256(entered.encode()).hexdigest() == config.get('admin_hash',''):
            self.show_admin_dashboard()
        else:
            messagebox.showerror("Error", "Invalid Password!")

    def do_user_login(self):
        user = self.entry_username.get().strip()
        pwd  = self.entry_userpass.get()

        if not user or not pwd:
            self.login_status.configure(text="Username aur password dono required hain")
            return

        self.login_status.configure(text="Connecting...", text_color=CLASSIC_ACCENT)
        self.root.update()

        # Try autotyper_login first (No OTP)
        res = self.api.call({'action': 'autotyper_login', 'username': user, 'password': pwd})

        if res and res.get('status') == 'success':
            self._finalize_login(user, res.get('role', 'deo'))
            return

        # Fallback to login_init
        self.login_status.configure(text="Alternate login try kar raha hu...", text_color=WARNING_COLOR)
        self.root.update()

        res = self.api.call({'action': 'login_init', 'username': user, 'password': pwd})
        if res:
            if res.get('status') == 'success':
                self._finalize_login(user, res.get('role', 'deo'))
            elif res.get('status') == 'otp_sent':
                self.login_status.configure(text="OTP sent to WhatsApp", text_color=DONE_COLOR)
                otp = ctk.CTkInputDialog(text="Enter OTP from WhatsApp:", title="OTP Verification").get_input()
                if otp:
                    self.login_status.configure(text="OTP verify ho raha hai...", text_color=CLASSIC_ACCENT)
                    self.root.update()
                    verify = self.api.call({'action': 'verify_otp', 'username': user, 'password': pwd, 'otp': otp})
                    if verify and verify.get('status') == 'success':
                        self._finalize_login(user, verify.get('role', 'deo'))
                    else:
                        self.login_status.configure(text=verify.get('message','OTP Failed') if verify else "Server Error", text_color=ERROR_COLOR)
            else:
                msg = res.get('message', 'Login Failed')
                # Check for QC Dashboard disabled message
                if 'QC Dashboard' in msg or 'Disable' in msg:
                    self.login_status.configure(text=f"🔴 {msg}", text_color=ERROR_COLOR)
                else:
                    self.login_status.configure(text=msg, text_color=ERROR_COLOR)
        else:
            self.login_status.configure(text="Server se connect nahi ho pa raha. Internet check karo.", text_color=ERROR_COLOR)

    def _finalize_login(self, username, role='deo'):
        self.current_user = username
        self.current_role = role

        user_folder = os.path.join(USER_DATA_DIR, username)
        if not os.path.exists(user_folder):
            try:
                os.makedirs(user_folder)
            except:
                pass

        self.session_rows_completed = 0
        self.row_times  = []
        self.failed_records = []
        self.reported_records = {}

        if self.batch_queue.size() > 0:
            self._send_batch_async()

        self._start_backup_timer()
        self.setup_main_ui()
        self._start_sync()
        self._load_reported_records_async()  # Load reports in background
        self._check_admin_replies()          # Check pending admin replies

    # ──────────────────────────────────────────
    # SYNC & REPORTS LOADING
    # ──────────────────────────────────────────
    def _start_sync(self):
        self._do_sync()
        import random
        base = config.get('sync_interval', 25)
        interval = (base + random.randint(0, 10)) * 1000
        self.sync_timer_id = self.root.after(interval, self._start_sync)

    def _do_sync(self):
        if self.current_file_path != "Server_Data" or self.df is None or self.is_running:
            return
        threading.Thread(target=self._sync_worker, daemon=True).start()
        threading.Thread(target=self._load_reported_records_bg, daemon=True).start()
        # Also check admin replies and qc status
        self._check_admin_replies()
        threading.Thread(target=self._sync_qc_status, daemon=True).start()
    
    def _sync_qc_status(self):
        """Check QC enabled/disabled and hide QC-related UI elements"""
        try:
            res = self.api.call({'action': 'get_qc_status'}, timeout=8)
            if res and res.get('status') == 'success':
                new_qc = res.get('qc_enabled', '1') == '1'
                if new_qc != self.qc_enabled:
                    self.qc_enabled = new_qc
                    self.root.after(0, lambda: self._apply_qc_visibility(new_qc))
        except:
            pass
    
    def _apply_qc_visibility(self, enabled):
        """Hide/show 2nd QC related UI elements based on QC system state"""
        try:
            if enabled:
                # Show 2nd QC Done filter checkbox
                self.chk_qc_done_var.set(True)
            else:
                # Hide/uncheck 2nd QC Done filter 
                self.chk_qc_done_var.set(False)
                self.on_filter_change()
        except:
            pass

    def _silent_reload(self):
        """Reload server data silently — picks up new records without disrupting current work"""
        if self.is_running:
            return
        def _do():
            try:
                res = self.api.call({'action': 'get_done_records_for_autotyper',
                                     'username': self.current_user}, timeout=15)
                if res and res.get('status') == 'success':
                    data   = res.get('data', [])
                    meta   = res.get('meta', [])
                    if not data:
                        return
                    import pandas as pd
                    df = pd.DataFrame(data)
                    old_len    = len(self.df) if self.df is not None else 0
                    new_len    = len(df)
                    added      = new_len - old_len
                    new_status = [self._status_from_server(m.get('status','')) for m in meta]
                    reports_map = res.get('reports_map', {})
                    self.df              = df
                    self.row_status      = new_status
                    if reports_map:
                        self.reported_records = reports_map
                    self.root.after(0, lambda: self._after_silent_reload(added))
            except Exception as e:
                logger.error(f"_silent_reload: {e}")
        threading.Thread(target=_do, daemon=True).start()

    def _after_silent_reload(self, added_count):
        self.populate_row_list()
        self.update_status_display()
        self._update_report_badge()
        if added_count > 0:
            try:
                self.lbl_status.configure(
                    text=f"✅ {added_count} naye record(s) queue mein aaye!",
                    text_color="#27ae60")
                self.root.after(4000, self.update_status_display)
            except:
                pass

    def _sync_worker(self):
        try:
            res = self.api.call({'action': 'get_done_records_for_autotyper', 'username': self.current_user}, timeout=10)
            if res and res.get('status') == 'success' and 'meta' in res:
                meta = res['meta']
                
                # If record count changed → new records added → full reload
                if len(meta) != len(self.row_status) and self.df is not None:
                    logger.info(f"New records detected ({len(self.row_status)} → {len(meta)}), reloading...")
                    self.root.after(0, self._silent_reload)
                    return
                
                if len(meta) == len(self.row_status):
                    changed = False
                    for i, m in enumerate(meta):
                        new_status = self._status_from_server(m.get('status', ''))
                        if new_status != self.row_status[i]:
                            self.row_status[i] = new_status
                            changed = True
                    
                    # Sync reported status from meta
                    reports_map = res.get('reports_map', {})
                    if reports_map:
                        self.reported_records = reports_map
                        changed = True
                    
                    if changed:
                        self.root.after(0, self._refresh_list)
        except Exception as e:
            logger.error(f"_sync_worker: {e}")

    def _refresh_list(self):
        try:
            self.apply_filter()
            self.update_status_display()
        except:
            pass

    # ──────────────────────────────────────────
    # REPORT TO ADMIN - LOADING
    # ──────────────────────────────────────────
    def _load_reported_records_async(self):
        threading.Thread(target=self._load_reported_records_bg, daemon=True).start()

    def _load_reported_records_bg(self):
        """Load all open reports from both report_to_admin + critical_errors"""
        try:
            # Use dedicated autotyper endpoint (no admin role required, both tables)
            res = self.api.call({'action': 'get_reported_records_autotyper'}, timeout=10)
            if res and res.get('status') == 'success':
                reports_map = res.get('reports_map', {})
                self.reported_records = reports_map
                self.root.after(0, self._refresh_list)
                logger.info(f"Reports loaded: {len(reports_map)} records reported")
            else:
                # Fallback: try get_all_reports (admin only)
                res2 = self.api.call({'action': 'get_all_reports', 'status': 'open'}, timeout=10)
                if res2 and res2.get('status') == 'success':
                    new_dict = {}
                    for r in res2.get('reports', []):
                        rno = r.get('record_no', '')
                        if rno:
                            if rno not in new_dict:
                                new_dict[rno] = []
                            new_dict[rno].append(r)
                    self.reported_records = new_dict
                    self.root.after(0, self._refresh_list)
        except Exception as e:
            logger.error(f"Report load error: {e}")

    # ──────────────────────────────────────────
    # 7. ADMIN DASHBOARD
    # ──────────────────────────────────────────
    def show_admin_dashboard(self):
        self.clear_screen()
        self.root.geometry("720x660")

        head = ctk.CTkFrame(self.root, height=50, corner_radius=0, fg_color="#2c3e50")
        head.pack(fill="x")
        ctk.CTkLabel(head, text="ADMIN PANEL", font=self.header_font, text_color="white").pack(side="left", padx=20)

        ctk.CTkButton(head, text="Change API URL",  width=120, height=30, fg_color="#e67e22", command=self._change_api_url).pack(side="right", padx=5)
        ctk.CTkButton(head, text="Change Password", width=120, height=30, fg_color="#8e44ad", command=self._change_admin_pass).pack(side="right", padx=5)
        ctk.CTkButton(head, text="Backup",          width=80,  height=30, fg_color="#27ae60", command=lambda: self._create_backup(False)).pack(side="right", padx=5)
        ctk.CTkButton(head, text="Logout",          width=80,  height=30, fg_color="#c0392b", command=self.show_welcome_screen).pack(side="right", padx=5)

        ctk.CTkLabel(self.root, text=f"API: {config.get('api_url', DEFAULT_API_URL)}", font=("Segoe UI", 10), text_color="gray").pack(anchor="w", padx=10, pady=3)

        ctrl = ctk.CTkFrame(self.root, fg_color="transparent")
        ctrl.pack(fill="x", pady=5, padx=10)

        ctk.CTkLabel(ctrl, text="User:", font=self.btn_font).pack(side="left", padx=5)
        users = [d for d in os.listdir(USER_DATA_DIR) if os.path.isdir(os.path.join(USER_DATA_DIR, d))] if os.path.exists(USER_DATA_DIR) else []
        self.combo_user = ctk.CTkComboBox(ctrl, values=users, width=150)
        self.combo_user.pack(side="left", padx=5)
        ctk.CTkButton(ctrl, text="Load", command=self._load_reports, width=80).pack(side="left", padx=10)
        self.lbl_total = ctk.CTkLabel(ctrl, text="Total: 0", font=self.sub_header_font, text_color=CLASSIC_ACCENT)
        self.lbl_total.pack(side="right", padx=20)

        style = ttk.Style()
        style.theme_use("clam")
        cols = ("Date", "Time", "File", "Row", "Duration")
        self.tree = ttk.Treeview(self.root, columns=cols, show="headings")
        for c in cols:
            self.tree.heading(c, text=c)
            self.tree.column(c, width=120)
        scroll = ttk.Scrollbar(self.root, orient="vertical", command=self.tree.yview)
        self.tree.configure(yscroll=scroll.set)
        self.tree.pack(side="left", fill="both", expand=True, padx=10, pady=10)
        scroll.pack(side="right", fill="y", pady=10)

        self.add_footer()

    def _change_api_url(self):
        current = config.get('api_url', DEFAULT_API_URL)
        new_url = ctk.CTkInputDialog(text=f"Current: {current}\n\nNew API URL:", title="Change API URL").get_input()
        if new_url and new_url.strip():
            new_url = new_url.strip()
            if not new_url.startswith('http'):
                new_url = 'https://' + new_url
            self.api.update_url(new_url)
            messagebox.showinfo("Done", f"API URL updated:\n{new_url}")

    def _load_reports(self):
        for item in self.tree.get_children():
            self.tree.delete(item)
        user = self.combo_user.get()
        if not user:
            return
        log_file = os.path.join(USER_DATA_DIR, user, "activity_log.csv")
        count = 0
        if os.path.exists(log_file):
            try:
                with open(log_file, 'r', newline='', encoding='utf-8') as f:
                    for row in reversed(list(csv.reader(f))):
                        if row and len(row) >= 5:
                            self.tree.insert("", "end", values=row)
                            count += 1
            except:
                pass
        self.lbl_total.configure(text=f"Total: {count}")

    def _change_admin_pass(self):
        new_pass = ctk.CTkInputDialog(text="New Password (min 6 chars):", title="Change Password").get_input()
        if new_pass and len(new_pass) >= 6:
            config.set('admin_hash', hashlib.sha256(new_pass.encode()).hexdigest())
            messagebox.showinfo("Done", "Password changed!")
        elif new_pass:
            messagebox.showerror("Error", "Min 6 characters required!")

    # ──────────────────────────────────────────
    # 8. MAIN USER DASHBOARD
    # ──────────────────────────────────────────
    def setup_main_ui(self):
        self.clear_screen()
        self.root.geometry("370x760")

        self.user_memory_file  = os.path.join(USER_DATA_DIR, self.current_user, "progress.json")
        self.current_file_path = "Server_Data"

        # ── Header ──
        header = ctk.CTkFrame(self.root, height=48, corner_radius=0, fg_color=CLASSIC_ACCENT)
        header.pack(fill="x")
        header.pack_propagate(False)
        ctk.CTkLabel(header, text=f"👤 {self.current_user}", font=("Segoe UI", 12, "bold"), text_color="white").pack(side="left", padx=10)
        
        # Report count badge (shows if there are open reports)
        self.lbl_report_badge = ctk.CTkLabel(header, text="", font=("Segoe UI", 10, "bold"), text_color="white",
                                              fg_color=REPORTED_COLOR, corner_radius=10, width=10)
        self.lbl_report_badge.pack(side="left", padx=2)
        
        ctk.CTkButton(header, text="LOGOUT", width=60, height=24, fg_color="#c0392b", command=self._logout).pack(side="right", padx=10)

        # ── Session Stats ──
        stats = ctk.CTkFrame(self.root, height=30, corner_radius=5, fg_color="white")
        stats.pack(fill="x", padx=10, pady=3)
        stats.pack_propagate(False)
        self.lbl_stats = ctk.CTkLabel(stats, text="📊 Session: 0 rows | Avg: 00:00", font=self.data_entry_font, text_color=TEXT_COLOR)
        self.lbl_stats.pack(pady=4)

        # ── Load Buttons Card ──
        card = ctk.CTkFrame(self.root, corner_radius=10, fg_color="#f9f9f9")
        card.pack(fill="x", padx=10, pady=5)

        btn_frame = ctk.CTkFrame(card, fg_color="transparent")
        btn_frame.pack(fill="x", padx=5, pady=8)
        self.btn_excel = ctk.CTkButton(btn_frame, text="📂 Load Excel", command=self.load_excel,
                                       font=self.btn_font, width=135, height=35, fg_color="#3498db")
        self.btn_excel.pack(side="left", padx=2, expand=True)
        self.btn_server = ctk.CTkButton(btn_frame, text="☁️ Load Server", command=self.load_server,
                                        font=self.btn_font, width=135, height=35, fg_color=CLASSIC_ACCENT)
        self.btn_server.pack(side="right", padx=2, expand=True)

        # ── Filter Checkboxes ──
        filter_frame = ctk.CTkFrame(card, fg_color="transparent")
        filter_frame.pack(fill="x", padx=5, pady=(0, 5))

        ctk.CTkLabel(filter_frame, text="Filter:", font=("Segoe UI", 10, "bold")).pack(side="left", padx=3)

        self.chk_done_var = ctk.BooleanVar(value=True)
        ctk.CTkCheckBox(filter_frame, text="1st QC Done", variable=self.chk_done_var,
                        command=self.on_filter_change, font=("Segoe UI", 9),
                        fg_color=DONE_COLOR, hover_color=DONE_COLOR, width=85).pack(side="left", padx=2)

        self.chk_qc_done_var = ctk.BooleanVar(value=True)
        ctk.CTkCheckBox(filter_frame, text="2nd QC Done", variable=self.chk_qc_done_var,
                        command=self.on_filter_change, font=("Segoe UI", 9),
                        fg_color=QC_DONE_COLOR, hover_color=QC_DONE_COLOR, width=85).pack(side="left", padx=2)

        self.chk_completed_var = ctk.BooleanVar(value=True)
        ctk.CTkCheckBox(filter_frame, text="Final Done", variable=self.chk_completed_var,
                        command=self.on_filter_change, font=("Segoe UI", 9),
                        fg_color=COMPLETED_COLOR, hover_color=COMPLETED_COLOR, width=75).pack(side="left", padx=2)

        self.chk_reported_var = ctk.BooleanVar(value=True)
        ctk.CTkCheckBox(filter_frame, text="⚠️ Reported", variable=self.chk_reported_var,
                        command=self.on_filter_change, font=("Segoe UI", 9),
                        fg_color=REPORTED_COLOR, hover_color=REPORTED_COLOR, width=80).pack(side="left", padx=2)

        # ── Speed Sliders ──
        slider_frame = ctk.CTkFrame(card, fg_color="transparent")
        slider_frame.pack(fill="x", padx=5, pady=(0, 8))

        f1 = ctk.CTkFrame(slider_frame, fg_color="transparent")
        f1.pack(side="left", expand=True)
        self.lbl_gap = ctk.CTkLabel(f1, text="Gap: 0.3s", font=("Segoe UI", 10))
        self.lbl_gap.pack()
        self.slider_gap = ctk.CTkSlider(f1, from_=0.1, to=2.0, width=100,
                                        command=lambda v: self.lbl_gap.configure(text=f"Gap: {v:.1f}s"))
        self.slider_gap.set(0.3)
        self.slider_gap.pack()

        f2 = ctk.CTkFrame(slider_frame, fg_color="transparent")
        f2.pack(side="right", expand=True)
        self.lbl_speed = ctk.CTkLabel(f2, text="Speed: 0.05s", font=("Segoe UI", 10))
        self.lbl_speed.pack()
        self.slider_speed = ctk.CTkSlider(f2, from_=0.01, to=0.2, width=100,
                                          command=lambda v: self.lbl_speed.configure(text=f"Speed: {v:.2f}s"))
        self.slider_speed.set(0.05)
        self.slider_speed.pack()

        # ── Timer & Status ──
        self.lbl_timer  = ctk.CTkLabel(self.root, text="00:00", font=self.timer_font, text_color=TEXT_COLOR)
        self.lbl_timer.pack(pady=(3, 0))
        self.lbl_status = ctk.CTkLabel(self.root, text="NO DATA", font=self.status_font, text_color="gray")
        self.lbl_status.pack()

        # ── Report Info Panel (shows when reported record is selected) ──
        self.report_info_frame = ctk.CTkFrame(self.root, fg_color="#fff3cd", corner_radius=6,
                                               border_width=1, border_color=REPORTED_COLOR)
        self.report_info_label = ctk.CTkLabel(self.report_info_frame, text="",
                                               font=("Segoe UI", 9), text_color="#856404",
                                               justify="left", wraplength=330)
        self.report_info_label.pack(padx=8, pady=4, anchor="w")
        # Hidden by default
        # (will be shown on select_row when record is reported)

        # ── Admin Reply Panel ──
        self.admin_reply_frame = ctk.CTkFrame(self.root, fg_color="#d1ecf1", corner_radius=6,
                                               border_width=1, border_color="#0c5460")
        self.admin_reply_label = ctk.CTkLabel(self.admin_reply_frame, text="",
                                               font=("Segoe UI", 9), text_color="#0c5460",
                                               justify="left", wraplength=330)
        self.admin_reply_label.pack(padx=8, pady=4, anchor="w")
        
        # Mark Resolve button inside reply panel
        self.btn_mark_resolve = ctk.CTkButton(self.admin_reply_frame,
                                               text="✅ Mark Resolve",
                                               command=self._mark_resolve_current,
                                               font=("Segoe UI", 9, "bold"),
                                               fg_color="#28a745", hover_color="#1e7e34",
                                               height=24, width=120)
        self.btn_mark_resolve.pack(pady=(0, 4))
        self._current_reply_ce_id = None
        # Hidden by default

        # ── Work Queue ──
        ctk.CTkLabel(self.root, text="📋 Work Queue:", font=self.sub_header_font,
                     anchor="w", text_color=TEXT_COLOR).pack(fill="x", padx=15, pady=(3, 0))
        self.list_frame = ctk.CTkScrollableFrame(self.root, height=155, corner_radius=5, fg_color="white")
        self.list_frame.pack(fill="both", expand=True, padx=10, pady=3)
        self.list_msg = ctk.CTkLabel(self.list_frame, text="Load data to see queue...", font=("Segoe UI", 10), text_color="gray")
        self.list_msg.pack(pady=30)

        # ── Navigation ──
        nav = ctk.CTkFrame(self.root, fg_color="transparent")
        nav.pack(fill="x", padx=10, pady=5)
        self.btn_prev = ctk.CTkButton(nav, text="<", command=self.prev_row, state="disabled", width=40, height=32)
        self.btn_prev.pack(side="left")
        self.lbl_counter = ctk.CTkLabel(nav, text="0 / 0", font=("Segoe UI", 12, "bold"), width=65)
        self.lbl_counter.pack(side="left", padx=4)
        self.entry_jump = ctk.CTkEntry(nav, width=50, height=30, placeholder_text="#")
        self.entry_jump.pack(side="left")
        self.entry_jump.bind('<Return>', lambda e: self.jump_row())
        ctk.CTkButton(nav, text="Go", command=self.jump_row, width=40, height=30).pack(side="left", padx=2)
        self.btn_next = ctk.CTkButton(nav, text="NEXT ▶ (F2)", command=self.next_row,
                                      state="disabled", width=105, height=32, fg_color=CLASSIC_ACCENT)
        self.btn_next.pack(side="right")

        # ── Action Buttons ──
        actions = ctk.CTkFrame(self.root, fg_color="transparent")
        actions.pack(fill="x", padx=10, pady=5)
        self.btn_start  = ctk.CTkButton(actions, text="▶ START (F1)",  command=self.start_typing, font=self.action_btn_font, fg_color="#27ae60", width=90, height=38, state="disabled")
        self.btn_start.pack(side="left", padx=2, expand=True, fill="x")
        self.btn_pause  = ctk.CTkButton(actions, text="⏸ PAUSE (F3)", command=self.toggle_pause, font=self.action_btn_font, fg_color="#f39c12", width=90, height=38, state="disabled")
        self.btn_pause.pack(side="left", padx=2, expand=True, fill="x")
        self.btn_stop   = ctk.CTkButton(actions, text="■ STOP (ESC)", command=self.stop_typing, font=self.action_btn_font, fg_color="#c0392b", width=90, height=38, state="disabled")
        self.btn_stop.pack(side="left", padx=2, expand=True, fill="x")

        # ── Report to Admin Button ──
        self.btn_report = ctk.CTkButton(self.root, text="⚠️ REPORT TO ADMIN (F4)",
                                        command=self.show_report_dialog,
                                        font=self.btn_font, fg_color=REPORTED_COLOR,
                                        hover_color="#e67e22", height=32, state="disabled")
        self.btn_report.pack(fill="x", padx=10, pady=(0, 5))

        self.add_footer()

    # ──────────────────────────────────────────
    # FILTER LOGIC
    # ──────────────────────────────────────────
    def on_filter_change(self):
        self.filter_done      = self.chk_done_var.get()
        self.filter_qc_done   = self.chk_qc_done_var.get()
        self.filter_completed = self.chk_completed_var.get()
        self.filter_reported  = self.chk_reported_var.get()
        self.apply_filter()

    def apply_filter(self):
        if self.df is None:
            return
        self.populate_row_list()
        self.update_status_display()

    def _matches_filter(self, status, record_no=""):
        """
        Status values from server:
          done / deo_done / pending_qc  → "1st QC Done"  (was Done)
          qc_done / qc_approved         → "2nd QC Done"
          Completed                     → "Final Completed"
          pending                       → "1st QC Pending"
        """
        is_reported = record_no in self.reported_records

        # If reported filter is OFF, don't show reported records at all
        if is_reported and not self.filter_reported:
            return False

        # If ONLY reported filter is active (show only reported)
        # We still apply status filter too
        if status == "Completed":        return self.filter_completed
        if status == "2nd QC Done":      return self.filter_qc_done
        if status in ("Done", "1st QC Done"): return self.filter_done
        if status == "1st QC Pending":   return self.filter_done or self.filter_qc_done
        return False

    # ──────────────────────────────────────────
    # 9. DATA LOADING
    # ──────────────────────────────────────────
    def _reorder_df(self):
        if self.df is None or self.df.empty:
            return
        target = None
        names = ["record_no", "record no", "recordno", "record_number"]
        for col in self.df.columns:
            if str(col).lower().strip() in names:
                target = col
                break
        if not target:
            for col in self.df.columns:
                if "record" in str(col).lower() and "no" in str(col).lower():
                    target = col
                    break
        if target:
            cols = list(self.df.columns)
            cols.remove(target)
            cols.insert(0, target)
            self.df = self.df[cols]

    def _status_from_server(self, status_str):
        """Convert server row_status to display status"""
        s = str(status_str).lower().strip()
        if s == 'completed':
            return "Completed"
        if s in ('qc_done', 'qc_approved'):
            return "2nd QC Done"
        if s in ('done', 'deo_done', 'pending_qc'):
            return "1st QC Done"
        if s == 'pending':
            return "1st QC Pending"
        return "1st QC Pending"

    def load_excel(self):
        path = filedialog.askopenfilename(filetypes=[("Excel", "*.xlsx;*.xls")])
        if not path:
            return
        self.btn_excel.configure(text="Loading...", state="disabled")
        self.root.update()
        try:
            self.df = pd.read_excel(path, dtype=str)
            self.df.fillna("", inplace=True)
            self.current_file_path = path
            self._reorder_df()
            self.row_status = ["1st QC Done"] * len(self.df)

            if os.path.exists(self.user_memory_file):
                try:
                    with open(self.user_memory_file, 'r') as f:
                        data = json.load(f)
                    if path in data:
                        self.row_status = data[path]
                except:
                    pass

            self.current_index = 0
            for i, s in enumerate(self.row_status):
                if s not in ("Completed",):
                    self.current_index = i
                    break

            try:
                self.list_msg.destroy()
            except:
                pass

            self.populate_row_list()
            self.update_status_display()
            self._enable_controls()

            fname = os.path.basename(path)
            self.btn_excel.configure(text=f"📂 {fname[:10]}...", state="normal", fg_color=DONE_COLOR)
            self.btn_server.configure(text="☁️ Load Server", state="normal", fg_color=CLASSIC_ACCENT)
        except Exception as e:
            messagebox.showerror("Error", str(e))
            self.btn_excel.configure(text="📂 Load Excel", state="normal")

    def load_server(self):
        self.btn_server.configure(text="Loading...", state="disabled")
        self.root.update()

        res = self.api.call({'action': 'get_done_records_for_autotyper', 'username': self.current_user})

        if res and res.get('status') == 'success':
            data = res.get('data', [])
            cols = res.get('columns', [])

            if not data:
                messagebox.showinfo("Info",
                    f"Koi records ready nahi hain.\n\nStatus:\n"
                    "• done / deo_done (2nd QC Pending)\n"
                    "• qc_done / qc_approved (2nd QC Done)\n\n"
                    f"User: {self.current_user}")
                self.btn_server.configure(text="☁️ Load Server", state="normal")
                return

            self.df = pd.DataFrame(data, columns=cols) if cols else pd.DataFrame(data)
            self.df.fillna("", inplace=True)
            self.current_file_path = "Server_Data"
            self._reorder_df()

            # Build row_status from meta
            if 'meta' in res and res['meta']:
                self.row_status = [self._status_from_server(m.get('status','')) for m in res['meta']]
            else:
                self.row_status = ["1st QC Done"] * len(self.df)

            # ── Populate reported_records directly from reports_map in response ──
            reports_map = res.get('reports_map', {})
            if reports_map:
                self.reported_records = reports_map
                logger.info(f"Reports loaded from server: {len(reports_map)} reported records")
            else:
                # Also read is_reported from meta for basic highlight
                meta_list = res.get('meta', [])
                cols_list = res.get('columns', [])
                new_reported = {}
                try:
                    rec_col_idx = cols_list.index('record_no') if 'record_no' in cols_list else 0
                except:
                    rec_col_idx = 0
                for i, m in enumerate(meta_list):
                    if m.get('is_reported', 0) or int(m.get('report_count', 0)) > 0:
                        rno = str(data[i][rec_col_idx]) if i < len(data) else ''
                        if rno and rno not in new_reported:
                            new_reported[rno] = [{'header_name': '⚠️ Issue reported', 'issue_details': '', 'reported_by': '', 'role': '', 'status': 'open'}]
                if new_reported:
                    self.reported_records = new_reported
            # ────────────────────────────────────────────────────────────────────

            # Find first non-completed record
            self.current_index = 0
            for i, s in enumerate(self.row_status):
                if s != "Completed":
                    self.current_index = i
                    break

            try:
                self.list_msg.destroy()
            except:
                pass

            self.populate_row_list()
            self.update_status_display()
            self._enable_controls()

            done_c      = sum(1 for s in self.row_status if s == "1st QC Done")
            qc_done_c   = sum(1 for s in self.row_status if s == "2nd QC Done")
            completed_c = sum(1 for s in self.row_status if s == "Completed")
            reported_c  = len(self.reported_records)
            btn_text    = f"☁️ P:{done_c} Q:{qc_done_c} C:{completed_c}"
            if reported_c: btn_text += f" ⚠️{reported_c}"
            self.btn_server.configure(text=btn_text, state="normal", fg_color=DONE_COLOR)
            self.btn_excel.configure(text="📂 Load Excel", state="normal", fg_color="#3498db")

            # Also refresh reports in background (in case reports_map was empty due to older API)
            self._load_reported_records_async()
        else:
            msg = res.get('message', 'Server Error') if res else "Connection Failed"
            messagebox.showerror("Error", msg)
            self.btn_server.configure(text="☁️ Load Server", state="normal")

    def _enable_controls(self):
        self.btn_start.configure(state="normal")
        self.btn_next.configure(state="normal")
        self.btn_prev.configure(state="normal")
        self.btn_report.configure(state="normal")

    # ──────────────────────────────────────────
    # 10. ROW LIST & NAVIGATION
    # ──────────────────────────────────────────
    def populate_row_list(self):
        for w in self.list_frame.winfo_children():
            w.destroy()

        if self.df is None:
            ctk.CTkLabel(self.list_frame, text="Load data to see queue...",
                         font=("Segoe UI", 10), text_color="gray").pack(pady=30)
            return

        # Build filtered indices
        filtered_indices = []
        for idx in range(len(self.df)):
            s  = self.row_status[idx] if idx < len(self.row_status) else ""
            rn = str(self.df.iloc[idx].iloc[0])
            if self._matches_filter(s, rn):
                filtered_indices.append(idx)

        if not filtered_indices:
            ctk.CTkLabel(self.list_frame, text="Filter se koi record match nahi karta",
                         font=("Segoe UI", 10), text_color="gray").pack(pady=30)
            self._update_report_badge()
            return

        try:
            current_pos = filtered_indices.index(self.current_index)
        except ValueError:
            current_pos = 0
            if filtered_indices:
                self.current_index = filtered_indices[0]

        start_pos = max(0, current_pos - 15)
        end_pos   = min(len(filtered_indices), current_pos + 20)

        if len(filtered_indices) > 50:
            ctk.CTkLabel(self.list_frame,
                         text=f"Showing {end_pos - start_pos} of {len(filtered_indices)} filtered",
                         font=("Segoe UI", 9), text_color="gray").pack(pady=2)

        for pos in range(start_pos, end_pos):
            idx    = filtered_indices[pos]
            status = self.row_status[idx] if idx < len(self.row_status) else ""
            rec_no = str(self.df.iloc[idx].iloc[0])
            is_reported = rec_no in self.reported_records
            selected    = (idx == self.current_index)

            # ── Status Icon ──
            if is_reported:
                icon = "⚠️"
            elif status == "Completed":
                icon = "🔴"
            elif status == "2nd QC Done":
                icon = "🔵"
            elif status == "1st QC Done":
                icon = "🟠"
            elif status == "1st QC Pending":
                icon = "🟡"
            else:
                icon = "⏳"

            # ── Row Colors ──
            if selected:
                fg, txt = CLASSIC_ACCENT, "white"
            elif is_reported:
                fg, txt = "#fff3cd", "#856404"   # Yellow highlight for reported
            elif status == "Completed":
                fg, txt = COMPLETED_COLOR, "white"
            elif status == "2nd QC Done":
                fg, txt = QC_DONE_COLOR, "white"
            elif status == "1st QC Done":
                fg, txt = DONE_COLOR, "white"
            else:
                fg, txt = "transparent", "#333333"

            # Label text — show report info inline
            label_text = f" {icon} {rec_no}"
            has_admin_reply = False
            reply_ce_id = None
            if is_reported:
                reports = self.reported_records.get(rec_no, [])
                first_header = reports[0].get('header_name', reports[0].get('field_name', 'Issue')) if reports else 'Issue'
                label_text = f" ⚠️ {rec_no}  [{first_header}]"
                # Check if admin has replied for this record
                for ce_id_k, rpl_v in self.admin_replies.items():
                    if str(rpl_v.get('record_no','')) == rec_no and rpl_v.get('status') == 'admin_reviewed':
                        has_admin_reply = True
                        reply_ce_id = ce_id_k
                        admin_remark = rpl_v.get('admin_remark','')
                        break

            if is_reported and has_admin_reply:
                # ── Reported + Admin Replied → Show card with Mark Resolve ──
                row_frame = ctk.CTkFrame(self.list_frame, fg_color="#d1ecf1",
                                         corner_radius=5, border_width=1, border_color="#0c5460")
                row_frame.pack(fill="x", padx=2, pady=2)

                # Record select button
                ctk.CTkButton(row_frame, text=f" 🔔 {rec_no}",
                              command=lambda i=idx: self.select_row(i),
                              anchor="w", font=self.row_list_font,
                              text_color="#0c5460", fg_color="transparent",
                              hover_color="#b8daff", height=26).pack(fill="x", padx=4, pady=(4, 0))

                # Admin reply text
                if admin_remark:
                    ctk.CTkLabel(row_frame,
                                 text=f"💬 Admin: {admin_remark[:60]}{'...' if len(admin_remark)>60 else ''}",
                                 font=("Segoe UI", 8), text_color="#155724",
                                 anchor="w", wraplength=290).pack(fill="x", padx=8, pady=(0,2))

                # Mark Resolve button
                ctk.CTkButton(row_frame, text="✅ Mark Resolve",
                              command=lambda ce=reply_ce_id, rn=rec_no: self._mark_resolve_inline(ce, rn),
                              font=("Segoe UI", 9, "bold"),
                              fg_color="#28a745", hover_color="#1e7e34",
                              height=24, width=130).pack(side="right", padx=6, pady=(0, 4))
            else:
                # ── Normal row ──
                ctk.CTkButton(self.list_frame, text=label_text,
                              command=lambda i=idx: self.select_row(i),
                              anchor="w", font=self.row_list_font,
                              text_color=txt, fg_color=fg, height=28).pack(fill="x", padx=2, pady=1)

        self._update_report_badge()

    def _update_report_badge(self):
        """Update the report count badge in header"""
        try:
            count = sum(1 for idx in range(len(self.row_status) if self.df is not None else 0)
                        if str(self.df.iloc[idx].iloc[0]) in self.reported_records)
            if count > 0:
                self.lbl_report_badge.configure(text=f" ⚠️ {count} Reported ", padx=4)
            else:
                self.lbl_report_badge.configure(text="")
        except:
            pass

    def select_row(self, index):
        if self.current_index == index and not self.is_running:
            pass  # Allow re-select to refresh report panel
        self.current_index = index
        self.populate_row_list()
        self.update_status_display()
        self._show_report_info_panel()  # Show/hide report panel
        self._show_admin_reply_panel()  # Show/hide admin reply panel

    def _show_report_info_panel(self):
        """Show report info if current record has open reports"""
        try:
            if self.df is None:
                return
            rec_no = str(self.df.iloc[self.current_index].iloc[0])
            if rec_no in self.reported_records:
                reports = self.reported_records[rec_no]
                lines = [f"⚠️ {len(reports)} Report(s) Open — Record: {rec_no}"]
                for r in reports:
                    header    = r.get('header_name') or r.get('field_name', 'Issue')
                    issue     = r.get('issue_details', '')
                    by        = r.get('reported_by', '')
                    role      = r.get('role', '')
                    lines.append(f"  • Header: {header}")
                    lines.append(f"    Issue : {issue}")
                    if by:
                        lines.append(f"    By    : {role.upper()} — {by}")
                self.report_info_label.configure(text="\n".join(lines))
                self.report_info_frame.pack(fill="x", padx=10, pady=2, before=self.lbl_timer)
            else:
                self.report_info_frame.pack_forget()
        except Exception as e:
            try:
                self.report_info_frame.pack_forget()
            except:
                pass

    def _mark_resolve_inline(self, ce_id, record_no):
        """Mark Resolve from work queue inline button — ce_id + record_no dono bhejta hai"""
        # ce_id or record_no - at least one required
        if not ce_id and not record_no:
            return
        
        def _do():
            payload = {
                'action'   : 'mark_resolved_by_user',
                'ce_id'    : ce_id if ce_id else 0,
                'record_no': record_no
            }
            res = self.api.call(payload, timeout=12)
            # Log debug info to see what DB returned
            if res:
                logger.info(f"Mark Resolve: ce_id={res.get('ce_id_used','?')} rn={res.get('rn_used','?')} ce_affected={res.get('ce_affected','?')} rta={res.get('rta_affected','?')} steps={res.get('debug_steps','?')}")
            # Both 'success' and 'Already resolved' treated as OK
            ok = res and res.get('status') == 'success'
            if ok:
                # Mark as resolved locally so _check_admin_replies won't re-add
                if ce_id:
                    self.shown_reply_ids.add(ce_id)
                # Remove from admin_replies
                if ce_id and ce_id in self.admin_replies:
                    del self.admin_replies[ce_id]
                # Remove ALL ce_ids for this record_no from admin_replies + shown set
                to_del = [k for k, v in self.admin_replies.items()
                          if str(v.get('record_no','')) == record_no]
                for k in to_del:
                    self.shown_reply_ids.add(k)
                    del self.admin_replies[k]
                if record_no in self.reported_records:
                    del self.reported_records[record_no]
                self.root.after(0, lambda: self._on_resolve_inline_done(record_no))
            else:
                msg = res.get('message','Failed') if res else 'Connection error'
                self.root.after(0, lambda: self.lbl_status.configure(
                    text=f"❌ {msg}", text_color="#e74c3c"))
                self.root.after(3000, self.update_status_display)
        
        threading.Thread(target=_do, daemon=True).start()

    def _on_resolve_inline_done(self, record_no):
        """After inline resolve - update list and panels"""
        try:
            # Hide admin reply frame if it was showing this record
            try:
                self.admin_reply_frame.pack_forget()
                self._current_reply_ce_id = None
                self.btn_mark_resolve.configure(text="✅ Mark Resolve", state="normal")
            except:
                pass
            self._update_report_badge()
            self.populate_row_list()
            self.update_status_display()
            # Show brief status update instead of blocking popup
            try:
                self.lbl_status.configure(text=f"✅ Resolved — Record #{record_no}", text_color="#27ae60")
                self.root.after(3000, self._refresh_list)
            except:
                pass
        except:
            pass

    def _mark_resolve_current(self):
        """Mark Resolve from autotyper - syncs to all dashboards"""
        if not self._current_reply_ce_id:
            return
        ce_id = self._current_reply_ce_id
        record_no = str(self.df.iloc[self.current_index].iloc[0]) if self.df is not None else ""
        
        self.btn_mark_resolve.configure(text="Resolving...", state="disabled")
        
        def _do_resolve():
            res = self.api.call({'action':'mark_resolved_by_user', 'ce_id':ce_id, 'record_no':record_no}, timeout=10)
            if res and res.get('status') == 'success':
                # Remove from admin_replies
                if ce_id in self.admin_replies:
                    del self.admin_replies[ce_id]
                # Remove from reported_records if no more open reports
                if record_no in self.reported_records:
                    del self.reported_records[record_no]
                self.root.after(0, lambda: self._on_resolve_success(record_no))
            else:
                msg = res.get('message', 'Failed') if res else 'Connection error'
                self.root.after(0, lambda: self.btn_mark_resolve.configure(text="✅ Mark Resolve", state="normal"))
                self.root.after(0, lambda: self.lbl_status.configure(text=f"❌ Resolve failed", text_color="#e74c3c"))
        
        threading.Thread(target=_do_resolve, daemon=True).start()
    
    def _on_resolve_success(self, record_no):
        try:
            self.admin_reply_frame.pack_forget()
            self._current_reply_ce_id = None
            self.btn_mark_resolve.configure(text="✅ Mark Resolve", state="normal")
            self._show_report_info_panel()
            self._update_report_badge()
            self.apply_filter()
            try:
                self.lbl_status.configure(text=f"✅ Resolved — Record #{record_no}", text_color="#27ae60")
                self.root.after(3000, self._refresh_list)
            except:
                pass
        except:
            pass
    
    def _check_admin_replies(self):
        """Poll admin replies and show notification panel"""
        def _bg():
            try:
                res = self.api.call({'action':'get_admin_replies_for_user'}, timeout=10)
                if res and res.get('status') == 'success':
                    replies = res.get('replies', [])
                    new_found = False
                    for rpl in replies:
                        ce_id = rpl.get('id')
                        if not ce_id:
                            continue
                        # Skip already-resolved/dismissed replies
                        if ce_id in self.shown_reply_ids:
                            continue
                        if rpl.get('status') == 'admin_reviewed':
                            self.admin_replies[ce_id] = rpl
                            new_found = True
                    
                    # Also check reported records directly for any admin_reviewed status
                    if self.df is not None and self.reported_records:
                        for rec_no in list(self.reported_records.keys()):
                            # Check if already in replies
                            already = any(
                                str(v.get('record_no','')) == rec_no 
                                for v in self.admin_replies.values()
                            )
                            if not already:
                                # Fetch ce details by record_no
                                r2 = self.api.call({
                                    'action': 'get_ce_for_record',
                                    'record_no': rec_no
                                }, timeout=8)
                                if r2 and r2.get('status') == 'success' and r2.get('ce'):
                                    for ce in r2['ce']:
                                        cid = ce.get('id')
                                        if cid and ce.get('status') == 'admin_reviewed':
                                            self.admin_replies[cid] = ce
                                            new_found = True
                    
                    if new_found:
                        self.root.after(0, self._show_admin_reply_panel)
                        self.root.after(0, self.populate_row_list)
            except Exception as e:
                logger.error(f"_check_admin_replies error: {e}")
        threading.Thread(target=_bg, daemon=True).start()
    
    def _show_admin_reply_panel(self):
        """Show admin reply notification for current record"""
        try:
            if self.df is None:
                return
            rec_no = str(self.df.iloc[self.current_index].iloc[0])
            # Find replies for current record
            cur_replies = [(ce_id, rpl) for ce_id, rpl in self.admin_replies.items()
                           if str(rpl.get('record_no','')) == rec_no]
            if cur_replies:
                ce_id, rpl = cur_replies[0]
                self._current_reply_ce_id = ce_id
                self.shown_reply_ids.add(ce_id)
                img_info = f" | 📷 {rpl['image_no']}" if rpl.get('image_no') else ""
                txt = f"🔔 Admin Reply — Record #{rpl.get('record_no','')}{img_info}\n"
                txt += f"Header: {rpl.get('error_field','')}\n"
                txt += f"Reply: {rpl.get('admin_remark','')}\n"
                txt += f"({rpl.get('reviewed_at','')[:16]})"
                self.admin_reply_label.configure(text=txt)
                self.admin_reply_frame.pack(fill="x", padx=10, pady=2, before=self.lbl_timer)
                self.btn_mark_resolve.configure(state="normal", text="✅ Mark Resolve")
            else:
                # Check if any replies exist at all - show summary
                total_replies = len([r for r in self.admin_replies.values() if r.get('status') == 'admin_reviewed'])
                if total_replies > 0 and not self.admin_reply_frame.winfo_ismapped():
                    # Show a notification in status
                    pass
        except Exception as e:
            logger.error(f"Reply panel error: {e}")
    
    def update_status_display(self):
        if self.df is None or not self.row_status:
            return

        if self.current_index >= len(self.row_status):
            return

        status = self.row_status[self.current_index]
        rec_no = str(self.df.iloc[self.current_index].iloc[0]) if self.df is not None else ""
        is_reported = rec_no in self.reported_records

        if is_reported:
            txt, color = f"⚠️ REPORTED — {status}", REPORTED_COLOR
        elif status == "1st QC Done":
            txt, color = "🟢 1st QC Done (Ready)", DONE_COLOR
        elif status == "2nd QC Done":
            txt, color = "🔵 2nd QC Done (Ready)", QC_DONE_COLOR
        elif status == "Completed":
            txt, color = "🔴 Final Completed", COMPLETED_COLOR
        elif status == "1st QC Pending":
            txt, color = "🟡 1st QC Pending", PENDING_COLOR
        else:
            txt, color = f"⏳ {status}", WARNING_COLOR

        self.lbl_status.configure(text=txt, text_color=color)

        filtered_count = sum(1 for i in range(len(self.row_status))
                             if self._matches_filter(self.row_status[i],
                                str(self.df.iloc[i].iloc[0]) if self.df is not None else ""))
        self.lbl_counter.configure(text=f"{self.current_index + 1}/{len(self.df)} ({filtered_count})")

        if self.current_index == len(self.df) - 1:
            self.btn_next.configure(text="FINISH", fg_color=ERROR_COLOR)
        else:
            self.btn_next.configure(text="NEXT ▶ (F2)", fg_color=CLASSIC_ACCENT)

    def jump_row(self):
        try:
            val = int(self.entry_jump.get())
            if self.df is not None and 1 <= val <= len(self.df):
                self.select_row(val - 1)
                self.entry_jump.delete(0, 'end')
        except:
            pass

    def prev_row(self):
        if self.df is None:
            return
        for i in range(self.current_index - 1, -1, -1):
            rn = str(self.df.iloc[i].iloc[0])
            if self._matches_filter(self.row_status[i], rn):
                self.select_row(i)
                return

    # ──────────────────────────────────────────
    # 11. REPORT TO ADMIN DIALOG  (F4)
    # ──────────────────────────────────────────
    REPORT_HEADERS = [
        "KYC Number", "Name", "Guardian Name", "Gender", "Marital Status",
        "DOB", "Address", "Landmark", "City", "Zip Code", "City Of Birth",
        "Nationality", "Photo Attachment", "Residential Status", "Occupation",
        "Officially Valid Documents", "Annual Income", "Broker Name",
        "Sub Broker Code", "Bank Serial No", "Second Applicant Name",
        "Amount Received From", "Amount", "ARN No", "Second Address",
        "Occupation/Profession", "Remarks", "Image Issue", "Data Mismatch", "Other"
    ]

    def show_report_dialog(self):
        """Open Report to Admin dialog (F4)"""
        if self.df is None or self.current_index >= len(self.df):
            messagebox.showwarning("Warning", "Pehle koi record select karo")
            return

        rec_no = str(self.df.iloc[self.current_index].iloc[0])

        dialog = ctk.CTkToplevel(self.root)
        dialog.title(f"⚠️ Report to Admin — {rec_no}")
        dialog.geometry("520x520")
        dialog.attributes('-topmost', True)
        dialog.grab_set()

        # ── Title ──
        ctk.CTkLabel(dialog, text=f"⚠️ Report Issue to Admin",
                     font=("Segoe UI", 16, "bold"), text_color=REPORTED_COLOR).pack(pady=(15, 3))
        ctk.CTkLabel(dialog, text=f"Record No: {rec_no}",
                     font=("Segoe UI", 13, "bold"), text_color=CLASSIC_ACCENT).pack()
        ctk.CTkLabel(dialog, text=f"Reported By: {self.current_user}  |  Role: {getattr(self,'current_role','deo').upper()}",
                     font=("Segoe UI", 10), text_color="gray").pack(pady=(0, 8))

        # ── Existing Reports ──
        if rec_no in self.reported_records:
            existing = self.reported_records[rec_no]
            ex_frame = ctk.CTkFrame(dialog, fg_color="#fff3cd", corner_radius=8,
                                     border_width=1, border_color=REPORTED_COLOR)
            ex_frame.pack(fill="x", padx=20, pady=(0, 8))
            ctk.CTkLabel(ex_frame, text=f"⚠️ {len(existing)} Existing Open Report(s):",
                         font=("Segoe UI", 10, "bold"), text_color="#856404").pack(anchor="w", padx=10, pady=(6,2))
            for r in existing:
                header = r.get('header_name') or r.get('field_name', '?')
                issue  = r.get('issue_details', '')
                by     = r.get('reported_by', '')
                status = r.get('status', 'open')
                status_badge = "✅ Solved" if status == 'solved' else "🔴 Open"
                ctk.CTkLabel(ex_frame,
                             text=f"  • {header}: {issue[:60]}{'...' if len(issue)>60 else ''} [{status_badge}] by {by}",
                             font=("Segoe UI", 9), text_color="#856404",
                             wraplength=470).pack(anchor="w", padx=10)
            ctk.CTkLabel(ex_frame, text="").pack(pady=3)

        # ── Header Dropdown ──
        ctk.CTkLabel(dialog, text="Select Header (Field with Issue):",
                     font=("Segoe UI", 11, "bold")).pack(anchor="w", padx=20, pady=(5, 0))
        header_var = ctk.StringVar(value="")
        header_menu = ctk.CTkComboBox(dialog, values=self.REPORT_HEADERS,
                                      variable=header_var, width=460, height=35)
        header_menu.pack(padx=20, pady=5)

        # ── Issue Details ──
        ctk.CTkLabel(dialog, text="Issue Details (kya problem hai describe karo):",
                     font=("Segoe UI", 11, "bold")).pack(anchor="w", padx=20, pady=(8, 0))
        issue_text = ctk.CTkTextbox(dialog, height=100, width=460, font=("Segoe UI", 11))
        issue_text.pack(padx=20, pady=5)

        status_lbl = ctk.CTkLabel(dialog, text="", font=("Segoe UI", 10))
        status_lbl.pack(pady=2)

        def do_submit():
            header = header_var.get().strip()
            issue  = issue_text.get("1.0", "end").strip()
            if not header or header == "":
                status_lbl.configure(text="⚠️ Header/Field select karo", text_color=ERROR_COLOR)
                return
            if not issue:
                status_lbl.configure(text="⚠️ Issue details likhna zaroori hai", text_color=ERROR_COLOR)
                return

            status_lbl.configure(text="Submitting...", text_color=CLASSIC_ACCENT)
            dialog.update()

            res = self.api.call({
                'action'       : 'submit_report_to_admin',
                'record_no'    : rec_no,
                'header_name'  : header,
                'issue_details': issue,
                'reported_from': 'autotyper'
            })

            if res and res.get('status') == 'success':
                status_lbl.configure(text="✅ Report submit ho gaya!", text_color=DONE_COLOR)
                # Add to local reported_records
                if rec_no not in self.reported_records:
                    self.reported_records[rec_no] = []
                self.reported_records[rec_no].append({
                    'header_name'   : header,
                    'issue_details' : issue,
                    'reported_by'   : self.current_user,
                    'role'          : getattr(self, 'current_role', 'deo'),
                    'status'        : 'open'
                })
                self.root.after(800, dialog.destroy)
                self.root.after(900, self.populate_row_list)
                self.root.after(900, self._show_report_info_panel)
                self.root.after(900, self.update_status_display)
            elif res and res.get('status') == 'warning':
                status_lbl.configure(text=f"⚠️ {res.get('message','')} — Duplicate check.", text_color=WARNING_COLOR)
            else:
                msg = res.get('message', 'Server Error') if res else "Connection Failed"
                status_lbl.configure(text=f"❌ {msg}", text_color=ERROR_COLOR)

        # ── Buttons ──
        btn_row = ctk.CTkFrame(dialog, fg_color="transparent")
        btn_row.pack(pady=10)
        ctk.CTkButton(btn_row, text="Cancel", command=dialog.destroy,
                      fg_color="gray", width=100).pack(side="left", padx=10)
        ctk.CTkButton(btn_row, text="⚠️ Submit Report", command=do_submit,
                      fg_color=REPORTED_COLOR, hover_color="#e67e22", width=160).pack(side="left", padx=10)

        # Enter key = submit
        dialog.bind('<Return>', lambda e: do_submit())
        header_menu.focus()

    # ──────────────────────────────────────────
    # 12. CORE TYPING ENGINE
    # ──────────────────────────────────────────
    def _update_stats(self):
        avg = "00:00"
        if self.row_times:
            avg = self.format_time(sum(self.row_times) / len(self.row_times))
        self.lbl_stats.configure(text=f"📊 Session: {self.session_rows_completed} rows | Avg: {avg}")

    def _log_activity(self):
        if not self.current_user:
            return
        log_file = os.path.join(USER_DATA_DIR, self.current_user, "activity_log.csv")
        now = datetime.now()
        duration = "00:00"
        if self.start_time > 0:
            elapsed = time.time() - self.start_time
            duration = self.format_time(elapsed)
            self.row_times.append(elapsed)
            if len(self.row_times) > 100:
                self.row_times.pop(0)
        fname = os.path.basename(self.current_file_path) if self.current_file_path != "Server_Data" else "Server_Data"
        try:
            with open(log_file, 'a', newline='', encoding='utf-8') as f:
                csv.writer(f).writerow([now.strftime("%Y-%m-%d"), now.strftime("%H:%M:%S"), fname, self.current_index + 1, duration])
        except:
            pass

    def next_row(self):
        if self.waiting_for_next:
            if self.is_saving:
                return
            self.is_saving = True

            rec_no      = str(self.df.iloc[self.current_index].iloc[0])
            current_idx = self.current_index

            self.row_status[current_idx] = "Completed"

            if self.current_file_path == "Server_Data":
                threading.Thread(target=self._send_single_record, args=(rec_no,), daemon=True).start()

            threading.Thread(target=self._save_progress, daemon=True).start()
            threading.Thread(target=self._log_activity, daemon=True).start()

            self.session_rows_completed += 1
            self._update_stats()

            self.waiting_for_next = False
            self.is_saving        = False
            self.btn_stop.configure(state="disabled")
            self.btn_start.configure(state="normal")
            self.btn_pause.configure(state="disabled")
            self.btn_next.configure(text="NEXT ▶ (F2)", fg_color=CLASSIC_ACCENT)

            # Find next non-completed filtered row
            next_idx = current_idx + 1
            while next_idx < len(self.df):
                rn = str(self.df.iloc[next_idx].iloc[0])
                if self._matches_filter(self.row_status[next_idx], rn) and self.row_status[next_idx] != "Completed":
                    break
                next_idx += 1

            if next_idx < len(self.df):
                self.select_row(next_idx)
            else:
                self._retry_failed_records()
                messagebox.showinfo("Done", "✅ Sab filtered records complete ho gaye!")
                self.populate_row_list()
                self.update_status_display()
        else:
            # Just navigate to next filtered row
            if self.df is None:
                return
            for i in range(self.current_index + 1, len(self.df)):
                rn = str(self.df.iloc[i].iloc[0])
                if self._matches_filter(self.row_status[i], rn):
                    self.select_row(i)
                    return

    def _send_single_record(self, record_no, retry_count=0):
        try:
            result = self.api.call({
                'action'     : 'batch_mark_completed_by_record_no',
                'record_nos' : json.dumps([record_no])
            }, timeout=15)
            if result and result.get('status') == 'success':
                logger.info(f"✓ Synced: {record_no}")
                if record_no in self.failed_records:
                    self.failed_records.remove(record_no)
                return True
            else:
                raise Exception(f"Server: {result}")
        except Exception as e:
            logger.warning(f"✗ Failed: {record_no} — {e}")
            if record_no not in self.failed_records:
                self.failed_records.append(record_no)
            if retry_count < 3:
                time.sleep(2 ** retry_count)
                return self._send_single_record(record_no, retry_count + 1)
            return False

    def _retry_failed_records(self):
        if not self.failed_records:
            return
        items = self.failed_records[:]
        self.failed_records = []
        if items:
            logger.info(f"Retrying {len(items)} failed records...")
            threading.Thread(target=self._upload_batch, args=(items,), daemon=True).start()

    def _send_batch_async(self):
        items = self.batch_queue.get_all()
        if items:
            self.batch_queue.clear()
            threading.Thread(target=self._upload_batch, args=(items,), daemon=True).start()
        self._retry_failed_records()

    def _upload_batch(self, items):
        try:
            result = self.api.call({
                'action'     : 'batch_mark_completed_by_record_no',
                'record_nos' : json.dumps(items)
            }, timeout=20)
            if result and result.get('status') == 'success':
                logger.info(f"✓ Batch sent: {len(items)} items")
            else:
                for item in items:
                    if item not in self.failed_records:
                        self.failed_records.append(item)
                logger.error("✗ Batch failed, added to retry queue")
        except Exception as e:
            for item in items:
                if item not in self.failed_records:
                    self.failed_records.append(item)
            logger.error(f"✗ Batch error: {e}")

    def _save_progress(self):
        try:
            data = {}
            if os.path.exists(self.user_memory_file):
                try:
                    with open(self.user_memory_file, 'r') as f:
                        data = json.load(f)
                except:
                    pass
            data[self.current_file_path] = self.row_status
            with open(self.user_memory_file, 'w') as f:
                json.dump(data, f)
        except Exception as e:
            logger.error(f"Save error: {e}")

    def start_typing(self):
        if self.is_running:
            return
        if self.df is None:
            return

        current_status = self.row_status[self.current_index]
        if current_status == "Completed":
            if not messagebox.askyesno("Warning", "Yeh record already COMPLETED hai. Phir bhi type karna hai?"):
                return

        self.is_running       = True
        self.is_paused        = False
        self.waiting_for_next = False

        self.btn_stop.configure(state="normal")
        self.btn_pause.configure(state="normal")
        self.btn_start.configure(state="disabled")

        threading.Thread(target=self._typing_worker, daemon=True).start()

    def stop_typing(self):
        self.is_running       = False
        self.is_paused        = False
        self.waiting_for_next = False
        self.btn_start.configure(state="normal")
        self.btn_pause.configure(state="disabled", text="⏸ PAUSE (F3)", fg_color="#f39c12")
        self.btn_next.configure(text="NEXT ▶ (F2)", fg_color=CLASSIC_ACCENT)
        self.lbl_status.configure(text="STOPPED ⏹", text_color=ERROR_COLOR)

    def toggle_pause(self):
        if not self.is_running and not self.is_paused:
            return
        self.is_paused = not self.is_paused
        if self.is_paused:
            self.btn_pause.configure(text="▶ RESUME", fg_color=DONE_COLOR)
            self.lbl_status.configure(text="PAUSED ⏸", text_color=WARNING_COLOR)
        else:
            self.btn_pause.configure(text="⏸ PAUSE (F3)", fg_color="#f39c12")
            self.lbl_status.configure(text="TYPING... ⚡", text_color=CLASSIC_ACCENT)

    def _update_timer(self):
        if self.waiting_for_next:
            return
        if self.is_running or self.is_paused:
            if not self.is_paused:
                elapsed = time.time() - self.start_time
                self.lbl_timer.configure(text=self.format_time(elapsed))
            self.root.after(100, self._update_timer)

    def _typing_worker(self):
        pyautogui.hotkey('alt', 'tab')
        time.sleep(1.0)

        self.start_time = time.time()
        self.root.after(0, self._update_timer)

        row   = self.df.iloc[self.current_index]
        gap   = self.slider_gap.get()
        speed = self.slider_speed.get()

        for val in row.values:
            while self.is_paused:
                time.sleep(0.1)
                if not self.is_running:
                    return
            if not self.is_running:
                break

            text = str(val)
            if text.lower() in ("nan", "none", ""):
                text = ""

            try:
                pyautogui.write(text, interval=speed)
                pyautogui.press('tab')
                time.sleep(gap)
            except pyautogui.FailSafeException:
                self.is_running = False
                self.root.after(0, lambda: messagebox.showerror("Failsafe", "Mouse corner mein chala gaya!"))
                return

        if self.is_running:
            self.waiting_for_next = True
            self.is_running       = False
            self.is_paused        = False

            elapsed = time.time() - self.start_time
            self.root.after(0, lambda: self.lbl_timer.configure(text=self.format_time(elapsed)))
            self.root.after(0, lambda: self.lbl_status.configure(
                text="✅ DONE — F2 ya SAVE & NEXT click karo", text_color=DONE_COLOR))
            self.root.after(0, lambda: self.btn_next.configure(text="SAVE & NEXT ✅ (F2)", fg_color=DONE_COLOR))
            self.root.after(0, lambda: self.btn_pause.configure(state="disabled"))

    # ──────────────────────────────────────────
    # 13. BACKUP & CLEANUP
    # ──────────────────────────────────────────
    def _create_backup(self, auto=False):
        try:
            ts   = datetime.now().strftime("%Y%m%d_%H%M%S")
            path = os.path.join(BACKUP_DIR, f"backup_{ts}.zip")
            with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
                for root, dirs, files in os.walk(USER_DATA_DIR):
                    for f in files:
                        fp = os.path.join(root, f)
                        z.write(fp, os.path.relpath(fp, USER_DATA_DIR))
            backups = sorted([f for f in os.listdir(BACKUP_DIR) if f.startswith("backup_")])
            for b in backups[:-10]:
                try:
                    os.remove(os.path.join(BACKUP_DIR, b))
                except:
                    pass
            if not auto:
                messagebox.showinfo("Done", f"Backup saved: backup_{ts}.zip")
            return True
        except Exception as e:
            if not auto:
                messagebox.showerror("Error", str(e))
            return False

    def _start_backup_timer(self):
        self._create_backup(True)
        self.backup_timer_id = self.root.after(300000, self._start_backup_timer)

    def _stop_timers(self):
        for tid in [self.backup_timer_id, self.sync_timer_id]:
            if tid:
                try:
                    self.root.after_cancel(tid)
                except:
                    pass

    def _logout(self):
        if messagebox.askyesno("Logout", "Logout karna chahte ho?"):
            self._send_batch_async()
            self._stop_timers()
            self.current_user     = None
            self.df               = None
            self.reported_records = {}
            self.show_welcome_screen()


# ==========================================
# MAIN
# ==========================================
if __name__ == "__main__":
    try:
        root = ctk.CTk()
        app  = EnterpriseAutoTyper(root)
        root.mainloop()
    except Exception as e:
        logging.error(f"Fatal error: {e}")
        messagebox.showerror("Error", str(e))