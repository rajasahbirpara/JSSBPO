<script>
    // --- DETAILED VALIDATION RULES (Extracted) ---
    
    const DATA_MISSING = "N.A"; 
    const VALID_DAYS = new Set(["monday","tuesday","wednesday","thursday","friday","saturday","sunday"]);

    // --- Helper Functions ---
    function normalize_spaces(t) {
        if (t == null) return "";
        return String(t).replace(/\t/g, " ").replace(/\s+/g, " ").trim();
    }
    
    function to_str(v) {
        if (v == null || v == DATA_MISSING || v == 'NaN') return "";
        return String(v);
    }
    
    function is_missing(v) {
        let x = normalize_spaces(v).toLowerCase();
        return x == "" || ["n.a", "n.a.", "na", "n/a", "none", "not available"].includes(x);
    }
    
    function proper_case(t) {
        if (t == null) return "";
        let o = [], c = true;
        for (let i = 0; i < t.length; i++) {
            let ch = t[i];
            if (/[a-zA-Z]/.test(ch)) {
                o.push(c ? ch.toUpperCase() : ch.toLowerCase());
                c = false;
            } else {
                o.push(ch);
                if (/\s/.test(ch) || !/[a-zA-Z0-9]/.test(ch)) c = true;
            }
        }
        return o.join("");
    }
    
    function handle_line_breaks(v) {
        if (v == null) return "";
        return normalize_spaces(String(v).replace(/\n/g, " "));
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        if (text == null) return "";
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // --- Processor Functions ---
    function process_name(v) {
        if (is_missing(v)) return { c: DATA_MISSING, r: [] };
        let t = handle_line_breaks(v).replace(/[^A-Za-z .']/g, ""); 
        t = proper_case(t);
        let p = t.split(/\s+/).filter(Boolean);
        if (p.length > 0) { 
            let fw = p[0].toLowerCase().replace(/\./g, ""); 
            if (["mr", "dr", "maj", "col", "miss", "ms", "mrs"].includes(fw)) 
                p[0] = fw.charAt(0).toUpperCase() + fw.slice(1).toLowerCase() + "."; 
        }
        return { c: normalize_spaces(p.join(" ")), r: [] };
    }
    
    function process_dob(v) {
        let r = []; 
        if (is_missing(v)) return { c: DATA_MISSING, r: r };
        
        let raw = normalize_spaces(v), i = 0; 
        while (i < raw.length && /[a-zA-Z]/.test(raw[i])) i++;
        
        let day = raw.substring(0, i), after = raw.substring(i), pre = "";
        if (day) { 
            if (!VALID_DAYS.has(day.toLowerCase())) r.push("Dob Invalid"); 
            if (/^\.\s*/.test(after)) { pre = day.toUpperCase() + ", "; after = after.replace(/^\.\s*/, ""); }
            else if (/^,\s*/.test(after)) { pre = day.toUpperCase() + ", "; after = after.replace(/^,\s*/, ""); }
            else { pre = day.toUpperCase() + " "; after = after.trimStart(); } 
        }
        
        let rest = after.replace(/\s+/g, "");
        if (/[a-zA-Z]/.test(rest)) { 
            r.push("Dob Invalid"); 
            return { c: normalize_spaces(pre + rest.toUpperCase()), r: r }; 
        }
        
        let dig = rest.replace(/[^0-9]/g, "/").replace(/\/+/g, "/").replace(/^\/|\/$/g, "").split("/");
        if (dig.length !== 3) { 
            r.push("Dob Invalid"); 
            return { c: normalize_spaces(pre + rest), r: r }; 
        }
        
        return { c: normalize_spaces(`${pre}${dig[0].padStart(2, "0")}/${dig[1].padStart(2, "0")}/${dig[2]}`), r: r };
    }

    function process_address(v) {
        let r = []; 
        if (is_missing(v)) return { c: DATA_MISSING, r: r };
        
        let t = normalize_spaces(v).split(/\s+/).filter(x => x.length > 0), out = [], inv = false;
        t.forEach((tok, i) => {
            let raw = tok, tail = ""; 
            if (raw.length > 1 && /[.,;:]$/.test(raw)) { tail = raw.slice(-1); raw = raw.slice(0, -1); }
            if (raw.toLowerCase() === "#floor") { out.push("#Floor" + tail); return; }
            if (/^#\d+$/.test(raw)) { out.push(raw.substring(1) + tail); return; }
            if (raw.includes("#") && !raw.startsWith("#")) { inv = true; out.push(tok); return; }
            if (raw === "#" && i !== 0 && i !== t.length - 1) { inv = true; out.push(tok); return; }
            out.push(tok);
        });
        
        let j = out.join(" ").replace(/\b(st|rd|ct)\.?\b/gi, (m, p) => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase() + ".").replace(/\.\.+/g, ".");
        if (inv) r.push("Addr Invalid"); 
        return { c: proper_case(j), r: r };
    }

    function process_zip(v) {
        let r = []; 
        if (is_missing(v)) return { c: DATA_MISSING, r: r };
        let t = to_str(v).replace(/[^0-9-]/g, ""); 
        if (t.replace(/-/g, "").length > 6) r.push("Zip Invalid"); 
        return { c: t, r: r };
    }

    function process_amount(v) {
        if (is_missing(v)) return { c: DATA_MISSING, r: [] };
        let t = to_str(v).replace(/[^0-9.]/g, ""); 
        if (!t) return { c: DATA_MISSING, r: [] };
        try { 
            let n = parseFloat(t); 
            if (isNaN(n)) return { c: DATA_MISSING, r: [] }; 
            return { c: `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`, r: [] }; 
        } catch (e) { return { c: DATA_MISSING, r: [] }; }
    }

    function process_ovd(v) { if (is_missing(v)) return { c: DATA_MISSING, r: [] }; return { c: proper_case(normalize_spaces(v)), r: [] }; }
    function process_upper(v) { if (is_missing(v)) return { c: DATA_MISSING, r: [] }; return { c: normalize_spaces(v).toUpperCase(), r: [] }; }
    function process_same(v) { if (is_missing(v)) return { c: DATA_MISSING, r: [] }; return { c: to_str(v).trim(), r: [] }; }
    function process_proper(v) { if (is_missing(v)) return { c: DATA_MISSING, r: [] }; return { c: proper_case(normalize_spaces(v)), r: [] }; }

    // --- Field Mapping ---
    const FIELD_PROCESSORS = {
        'name': process_name, 'guardian_name': process_name, 'broker_name': process_name, 'second_applicant_name': process_name,
        'dob': process_dob, 'address': process_address, 'second_address': process_address, 'zip_code': process_zip, 
        'city': process_same, 'city_of_birth': process_same, 'landmark': process_same, 
        'nationality': process_proper, 'gender': process_upper, 'marital_status': process_upper, 'residential_status': process_upper,
        'occupation': process_upper, 'occupation_profession': process_proper, 'officially_valid_documents': process_ovd, 
        'photo_attachment': process_upper, 'annual_income': process_amount, 'amount': process_amount,
        'kyc_number': process_same, 'sub_broker_code': process_same, 'bank_serial_no': process_same, 
        'arn_no': process_same, 'amount_received_from': process_same, 'remarks': process_proper
    };

    // --- Main Validation Function ---
    function validateField(el) {
        let $el = $(el);
        
        // Check for data-field (User) OR data-col (Admin)
        let f = $el.data('field') || $el.data('col');
        
        // Check if input (val) or table cell (text)
        let v = $el.is(':input') ? $el.val() : $el.text();

        let proc = FIELD_PROCESSORS[f] || process_same;
        let res = proc(v);
        
        let message = "", style = {};
        if (res.r && res.r.length > 0) {
            message = `⚠️ <strong>Invalid</strong>: ${escapeHtml(res.r.join(' '))}`;
            style = {'color': 'white', 'background-color': '#dc3545', 'border-color': '#dc3545'}; 
        } else if (res.c === DATA_MISSING) {
             if(v.trim().length === 0) {
                 // Empty logic
                 $('#validationTooltip').hide(); return;
             } else {
                 message = `✅ <strong>Valid</strong>: Missing (N.A)`;
                 style = {'color': '#155724', 'background-color': '#d4edda', 'border-color': '#c3e6cb'};
             }
        } else if (res.c !== v) {
            message = `💡 <strong>Suggestion</strong>: <br><code>${escapeHtml(res.c)}</code>`;
            style = {'color': '#004085', 'background-color': '#cce5ff', 'border-color': '#b8daff'}; 
        } else {
            message = `✅ <strong>Valid</strong>: ${escapeHtml(v)}`;
            style = {'color': '#155724', 'background-color': '#d4edda', 'border-color': '#c3e6cb'};
        }
        
        let rect = el.getBoundingClientRect();
        // Ensure tooltip exists
        if($('#validationTooltip').length === 0) {
            $('body').append('<div id="validationTooltip"></div>');
        }
        
        $('#validationTooltip').html(message).show().css({ 
            top: (rect.bottom + window.scrollY + 2) + 'px', 
            left: (rect.left + window.scrollX) + 'px', 
            ...style 
        });
    }
</script>