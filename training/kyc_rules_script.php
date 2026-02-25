<script>
    // ==========================================
    // 📜 KYC VALIDATION RULES & LOGIC
    // ==========================================

    // NOTE: config object is already defined in kyc_utility.php
    // Do not redefine here to avoid JavaScript conflicts

    // Constants
    const VALID_DAYS = new Set(["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"]);
    const HEADER_ORDER = [
        "name", "guardian name", "broker name", "2nd applicant name",
        "dob", "gender", "marital status", "city", "city of birth",
        "address", "2nd address", "zip", "nationality",
        "occupation", "landmark", "ovd", "photo attachment",
        "residential status", "annual income", "amount"
    ];
    
    const FIELD_LABEL = {};
    HEADER_ORDER.forEach(h => FIELD_LABEL[h] = h.charAt(0).toUpperCase() + h.slice(1));
    FIELD_LABEL["dob"] = "Dob";
    FIELD_LABEL["zip"] = "Zip";
    FIELD_LABEL["ovd"] = "Ovd";

    let DATA_MISSING = "N.A";
    let REMARKS_MISSING = "N.A.";

    // --- HELPER FUNCTIONS ---

    function normalizeSpaces(text) {
        if (text === null || text === undefined) return "";
        return String(text).replace(/\s+/g, " ").trim();
    }

    function isMissingValue(value) {
        // config variable is now defined above
        const v = normalizeSpaces(value).toLowerCase();
        return [""].concat(config.keywords).includes(v);
    }

    function formatCase(text) {
        let str = String(text);
        if(config.nameCasing === "upper") return str.toUpperCase();
        if(config.nameCasing === "lower") return str.toLowerCase();
        
        let out = "";
        let cap = true;
        for (let i = 0; i < str.length; i++) {
            let ch = str[i];
            if (/[a-zA-Z]/.test(ch)) {
                out += cap ? ch.toUpperCase() : ch.toLowerCase();
                cap = false;
            } else {
                out += ch;
                if (/\s/.test(ch) || !/[a-zA-Z0-9]/.test(ch)) cap = true;
            }
        }
        return out;
    }

    // --- FIELD SPECIFIC PROCESSORS ---

    function processNameField(value) {
        if (isMissingValue(value)) return DATA_MISSING;
        let v = normalizeSpaces(value).replace(/\n/g, " ");
        v = v.replace(/[^A-Za-z .']/g, ""); 
        v = formatCase(v);
        
        if(config.nameCasing === "proper") {
            let parts = v.split(" ");
            if (parts.length > 0) {
                let sal = parts[0].toLowerCase().replace(".", "");
                if (["mr", "dr", "mrs", "ms", "miss", "col", "maj"].includes(sal)) {
                    parts[0] = sal.charAt(0).toUpperCase() + sal.slice(1) + ".";
                }
            }
            return parts.join(" ");
        }
        return v;
    }

    function processDob(value, invalids) {
        if (isMissingValue(value)) return DATA_MISSING;
        let raw = normalizeSpaces(value);
        let i = 0;
        while (i < raw.length && /[a-zA-Z]/.test(raw[i])) i++;
        let day = raw.substring(0, i);
        let rest = raw.substring(i).replace(/^[,.\s]+/, "");
        let prefix = "";
        if (day) {
            if (!VALID_DAYS.has(day.toLowerCase())) invalids.add("dob");
            prefix = day.toUpperCase() + ", ";
        }
        let clean = rest.replace(/\s/g, "");
        if (/[A-Za-z]/.test(clean)) {
            invalids.add("dob");
            return prefix + clean.toUpperCase();
        }
        let parts = clean.replace(/[^\d]/g, "/").replace(/^\/|\/$/g, "").split("/");
        if (parts.length !== 3) {
            invalids.add("dob");
            return prefix + clean;
        }
        let p1 = parts[0].padStart(2, "0");
        let p2 = parts[1].padStart(2, "0");
        let yyyy = parts[2];
        return (config.dateFormat === "DMY") ? `${prefix}${p1}/${p2}/${yyyy}` : `${prefix}${p1}/${p2}/${yyyy}`;
    }

    function fixRdStCt(text) {
        if(!config.addressFixAbbr) return text;
        let parts = text.split(" ");
        let out = [];
        parts.forEach(p => {
            let raw = p.replace(/[.,]+$/, "");
            let tail = p.substring(raw.length);
            let lr = raw.toLowerCase();
            if (lr === "rd") out.push("Rd." + tail.replace(/\./g, ""));
            else if (lr === "st") out.push("St." + tail.replace(/\./g, ""));
            else if (lr === "ct") out.push("Ct." + tail.replace(/\./g, ""));
            else out.push(p);
        });
        return out.join(" ");
    }

    function processAddress(value, invalids) {
        if (isMissingValue(value)) return DATA_MISSING;
        let tokens = normalizeSpaces(value).split(" ");
        let out = [];
        let invalid = false;
        tokens.forEach((t, idx) => {
            let raw = t.replace(/[.,]+$/, "");
            let tail = t.substring(raw.length);
            if (config.addressRemoveHash) {
                if (/^#floor$/i.test(raw)) { out.push("#Floor" + tail); return; }
                if (/^#\d+$/.test(raw)) { out.push(raw.substring(1) + tail); return; }
                if (/^#\d+\w+$/.test(raw) || /\d+#\w+/.test(raw)) { invalid = true; out.push(t); return; }
                if (raw === "#") { if (idx !== 0 && idx !== tokens.length - 1) invalid = true; out.push(t); return; }
                if (raw.includes("#")) { if (!raw.startsWith("#") || idx !== 0) invalid = true; out.push(t); return; }
            }
            out.push(t);
        });
        let result = formatCase(fixRdStCt(out.join(" ")));
        if (invalid) invalids.add("address");
        return result;
    }

    function processZip(value, invalids) {
        if (isMissingValue(value)) return DATA_MISSING;
        let strVal = String(value);
        let digits = strVal.replace(/\D/g, "");
        if (digits.length > config.zipLen || digits === "0") invalids.add("zip");
        return value;
    }

    function processAmount(value) {
        if (isMissingValue(value)) return DATA_MISSING;
        try {
            let num = parseFloat(String(value).replace(/[^\d.]/g, ""));
            if (isNaN(num)) return DATA_MISSING;
            return `${config.currency}${num.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        } catch(e) { return DATA_MISSING; }
    }

    // --- MAIN CELL PROCESSOR ---

    function processCell(field, value, invalids) {
        let f = field.toLowerCase().trim();
        let s = value;
        if (["name", "guardian name", "broker name", "2nd applicant name"].includes(f)) return processNameField(s);
        if (f === "dob") return processDob(s, invalids);
        if (["address", "2nd address"].includes(f)) return processAddress(s, invalids);
        if (f === "zip") return processZip(s, invalids);
        if (["amount", "annual income"].includes(f)) return processAmount(s);
        if (["gender", "marital status", "photo attachment", "residential status", "occupation"].includes(f)) 
            return !isMissingValue(s) ? String(s).toUpperCase() : DATA_MISSING;
        if (["officially valid documents", "ovd"].includes(f)) 
            return !isMissingValue(s) ? formatCase(s) : DATA_MISSING;
        return !isMissingValue(s) ? String(s) : DATA_MISSING;
    }
</script>