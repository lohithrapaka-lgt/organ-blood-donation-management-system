<style>
    .medimatch-language-switcher {
        position: fixed;
        right: auto;
        bottom: 1.25rem;
        left: 1.25rem;
        z-index: 1100;
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .35rem .5rem .35rem .7rem;
        border: 1px solid rgba(0,0,0,.12);
        border-radius: 2rem;
        background: rgba(255,255,255,.96);
        box-shadow: 0 .35rem 1rem rgba(15,23,42,.12);
        font-size: .82rem;
    }
    .medimatch-language-switcher select { border: 0; outline: 0; background: transparent; font-weight: 600; cursor: pointer; }
    .medimatch-language-switcher i { color: #dc3545; }

    @media (max-width: 575.98px) {
        .medimatch-language-switcher {
            bottom: .75rem;
            left: .75rem;
        }
    }
</style>
<div class="medimatch-language-switcher" aria-label="Language selector">
    <span aria-hidden="true">文A</span>
    <label class="visually-hidden" for="medimatchLanguage">Language</label>
    <select id="medimatchLanguage" aria-label="Language">
        <option value="en">English</option>
        <option value="hi">हिन्दी</option>
    </select>
</div>
<script>
(function () {
    const selector = document.getElementById('medimatchLanguage');
    const hi = {
        home:'होम', about:'हमारे बारे में', features:'सुविधाएँ', contact:'संपर्क', login:'लॉग इन', register:'पंजीकरण',
        getStarted:'शुरू करें', registerNow:'अभी पंजीकरण करें', accessPortals:'पोर्टल चुनें', patient:'मरीज', donor:'दाता',
        hospital:'अस्पताल', bloodBank:'ब्लड बैंक', patientPortal:'मरीज पोर्टल', donorPortal:'दाता पोर्टल', hospitalPortal:'अस्पताल पोर्टल',
        bloodBankPortal:'ब्लड बैंक पोर्टल', welcomeBack:'वापसी पर स्वागत है', signIn:'अपने खाते में लॉग इन करें', email:'ईमेल पता',
        password:'पासवर्ड', submitLogin:'लॉग इन', noAccount:'खाता नहीं है?', registerHere:'यहाँ पंजीकरण करें', role:'भूमिका',
        selectRole:'अपनी भूमिका चुनें...', personalDetails:'व्यक्तिगत विवरण', fullName:'पूरा नाम', age:'उम्र', bloodGroup:'रक्त समूह',
        completeRegistration:'पंजीकरण पूरा करें', alreadyAccount:'पहले से खाता है?', loginHere:'यहाँ लॉग इन करें', dashboard:'डैशबोर्ड',
        myProfile:'मेरी प्रोफ़ाइल', submitBloodRequest:'रक्त अनुरोध भेजें', bloodDetails:'रक्त विवरण', organDetails:'अंग विवरण',
        rewards:'पुरस्कार और उपहार', donationHistory:'दान इतिहास', achievements:'उपलब्धियाँ', referEarn:'रेफर करें और कमाएँ',
        profileAvailability:'प्रोफ़ाइल और उपलब्धता', bloodCamps:'रक्त शिविर', shortages:'कमी', logout:'लॉग आउट',
        smartSystem:'स्मार्ट अंग और रक्त दान प्रबंधन प्रणाली', heroDescription:'जीवन बचाने के लिए दाताओं, मरीजों और अस्पतालों को जोड़ना।'
    };
    const staticTranslations = {
        'MediMatch':'मेडीमैच', 'Admin Panel':'व्यवस्थापक पैनल', 'Dashboard':'डैशबोर्ड', 'Overview Dashboard':'डैशबोर्ड अवलोकन',
        'Dashboard Overview':'डैशबोर्ड अवलोकन', 'Manage tracking data seamlessly.':'ट्रैकिंग डेटा आसानी से प्रबंधित करें।',
        'Patients':'मरीज', 'Donors':'दाता', 'Hospitals':'अस्पताल', 'Blood Banks':'ब्लड बैंक', 'Approvals':'अनुमोदन',
        'All Requests':'सभी अनुरोध', 'Reports':'रिपोर्ट', 'System Analytics':'सिस्टम विश्लेषण', 'Patient Management':'मरीज प्रबंधन',
        'Donor Registry':'दाता रजिस्टर', 'Hospital Network':'अस्पताल नेटवर्क', 'Blood Bank Control':'ब्लड बैंक नियंत्रण',
        'Pending Approvals':'लंबित अनुमोदन', 'All Patient Requests':'सभी मरीज अनुरोध', 'Ratings & Feedback':'रेटिंग और प्रतिक्रिया',
        'Request Fulfilled':'अनुरोध पूरा हुआ', 'Request Fulfilled!':'अनुरोध पूरा हुआ!', 'Fulfilled':'पूरा हुआ', 'Pending':'लंबित',
        'Approved':'अनुमोदित', 'Rejected':'अस्वीकृत', 'Status':'स्थिति', 'Date':'तारीख', 'Patient Name':'मरीज का नाम',
        'Blood Group':'रक्त समूह', 'Organ Details':'अंग विवरण', 'Organ Type':'अंग का प्रकार', 'Units Needed':'आवश्यक इकाइयाँ',
        'Priority':'प्राथमिकता', 'Blood Requests':'रक्त अनुरोध', 'Organ Requests':'अंग अनुरोध', 'Hospital':'अस्पताल',
        'Blood Bank':'ब्लड बैंक', 'No blood requests found.':'कोई रक्त अनुरोध नहीं मिला।', 'No organ requests found.':'कोई अंग अनुरोध नहीं मिला।',
        'No Pending Approvals':'कोई लंबित अनुमोदन नहीं', 'No ratings or feedback submitted yet.':'अभी कोई रेटिंग या प्रतिक्रिया नहीं दी गई है।',
        'Facility Ratings':'संस्था रेटिंग', 'Recent Feedback':'हाल की प्रतिक्रिया', 'Average Rating':'औसत रेटिंग', 'Responses':'प्रतिक्रियाएँ',
        'Feedback':'प्रतिक्रिया', 'Rating':'रेटिंग', 'Submit Feedback':'प्रतिक्रिया भेजें', 'Feedback (optional)':'प्रतिक्रिया (वैकल्पिक)',
        'My Donation History':'मेरा दान इतिहास', 'Facility Name':'संस्था का नाम', 'Donation Date':'दान की तारीख',
        'Rewards & Gifts':'पुरस्कार और उपहार', 'Donation History':'दान इतिहास', 'Profile & Availability':'प्रोफ़ाइल और उपलब्धता',
        'Blood Camps':'रक्त शिविर', 'Shortages':'कमी', 'System Composition':'सिस्टम संरचना', 'System Sustainability':'सिस्टम स्थिरता',
        'Total Patients Enrolled:':'कुल पंजीकृत मरीज:', 'Total Donors Verified:':'कुल सत्यापित दाता:',
        'Active Hospitals:':'सक्रिय अस्पताल:', 'Blood Bank Units:':'ब्लड बैंक इकाइयाँ:', 'View Report':'रिपोर्ट देखें',
        'View Receipt':'रसीद देखें', 'Download QR':'QR डाउनलोड करें', 'Print Receipt / Verification':'रसीद / सत्यापन प्रिंट करें',
        'Contact Us':'संपर्क करें', 'Saving Lives Through Smart Matching':'स्मार्ट मैचिंग से जीवन बचाना',
        'Hospital Portal':'अस्पताल पोर्टल', 'Organ Inventory':'अंग भंडार', 'Update Stock':'स्टॉक अपडेट करें',
        'Create Organ Request':'अंग अनुरोध बनाएँ', 'Requests':'अनुरोध', 'Profile':'प्रोफ़ाइल',
        'Organ Inventory Management & Request Handling':'अंग भंडार प्रबंधन और अनुरोध संचालन',
        'Evaluate & Create Organ Request for Patient':'मरीज के लिए मूल्यांकन और अंग अनुरोध बनाएँ',
        'Create Request for Patient':'मरीज के लिए अनुरोध बनाएँ', 'Current Organ Stock':'वर्तमान अंग भंडार',
        'TOTAL REQUESTS':'कुल अनुरोध', 'PENDING':'लंबित', 'FULFILLED':'पूरा हुआ', 'REJECTED':'अस्वीकृत',
        'Total Requests':'कुल अनुरोध', 'Create Request':'अनुरोध बनाएँ', 'Update Organ Stock':'अंग स्टॉक अपडेट करें',
        'Available':'उपलब्ध', 'Units Available':'उपलब्ध इकाइयाँ', 'Select Patient':'मरीज चुनें',
        'Select Organ':'अंग चुनें', 'Condition':'स्थिति', 'Critical':'गंभीर', 'Urgent':'तत्काल', 'Normal':'सामान्य',
        'Accept':'स्वीकार करें', 'Reject':'अस्वीकार करें', 'Save Changes':'परिवर्तन सहेजें', 'Phone':'फोन',
        'Location':'स्थान', 'License':'लाइसेंस', 'Specialization':'विशेषज्ञता', 'Name':'नाम',
        'Hospital initiate verified organ transplant requirements following patient clinical consultation. Automatically assigns priority score.':'अस्पताल मरीज के चिकित्सकीय परामर्श के बाद सत्यापित अंग प्रत्यारोपण आवश्यकताएँ शुरू करता है। प्राथमिकता स्कोर अपने आप निर्धारित होता है।',
        'Inventory deducted.':'भंडार से इकाइयाँ घटाई गईं।', 'Organ request':'अंग अनुरोध', 'Priority Score':'प्राथमिकता स्कोर'
    };
    const originalText = new WeakMap();

    function translateStaticText(language) {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        let node;
        while ((node = walker.nextNode())) {
            if (node.parentElement.closest('.medimatch-language-switcher, script, style')) continue;
            textNodes.push(node);
        }
        textNodes.forEach(function (textNode) {
            if (!originalText.has(textNode)) originalText.set(textNode, textNode.textContent);
            const source = originalText.get(textNode);
            if (language === 'en') {
                textNode.textContent = source;
                return;
            }
            let translated = source;
            Object.keys(staticTranslations).sort(function (a, b) { return b.length - a.length; }).forEach(function (english) {
                translated = translated.split(english).join(staticTranslations[english]);
            });
            textNode.textContent = translated;
        });
    }

    function apply(language) {
        document.documentElement.lang = language === 'hi' ? 'hi' : 'en';
        translateStaticText(language);
        document.querySelectorAll('[data-i18n]').forEach(function (element) {
            element.textContent = language === 'hi' ? (hi[element.dataset.i18n] || element.dataset.i18nEnglish) : element.dataset.i18nEnglish;
        });
        selector.value = language;
        localStorage.setItem('medimatch-language', language);
    }
    document.addEventListener('DOMContentLoaded', function () { apply(localStorage.getItem('medimatch-language') || 'en'); });
    selector.addEventListener('change', function () { apply(selector.value); });
})();
</script>
