// ===========================================================================
// TPB DEMAND SURVEY MODAL - Customer Checkout Gate
// ===========================================================================

function showSurveyModal(questionnaireSet) {
    const existing = document.getElementById('survey-modal-overlay');
    if (existing) existing.remove();
    
    const modal = document.createElement('div');
    modal.id = 'survey-modal-overlay';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10001;' +
        'display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';
    
    // Questionnaire sets
    const q1ATT = [
        'Purchasing halal-certified food from this restaurant would be a WISE decision for me.',
        'Purchasing halal-certified food from this restaurant would be a BENEFICIAL choice.',
        'Buying halal-certified food from this restaurant would be IMPORTANT to me.',
        'Purchasing halal-certified food from this restaurant would be VALUABLE to me.',
        'Buying halal-certified food from this restaurant would be FAVORABLE to me.'
    ];
    const q1SN = [
        'Most people whose opinions I value would APPROVE of me purchasing halal-certified food from this restaurant.',
        'Most people who are IMPORTANT to me think I should purchase halal-certified food from this restaurant.',
        'Most people who influence my behavior believe I should buy halal-certified food from this restaurant.',
        'People in my life whose opinions I value would PREFER that I purchase halal-certified food from this restaurant.',
        'Most people I RESPECT AND ADMIRE would purchase halal-certified food from this restaurant.'
    ];
    const q1PBC = [
        'For me, purchasing halal-certified food from this restaurant would be EASY.',
        'If I wanted to, I COULD purchase halal-certified food from this restaurant.',
        'I am CONFIDENT that I can purchase halal-certified food from this restaurant.',
        'It is ENTIRELY UP TO ME whether or not to purchase halal-certified food from this restaurant.',
        'I have complete CONTROL over purchasing halal-certified food from this restaurant.'
    ];
    
    const q2ATT = [
        'Purchasing food from this halal-certified restaurant would be a WISE decision for me.',
        'Purchasing food from this halal-certified restaurant would be a BENEFICIAL choice.',
        'Buying food from this halal-certified restaurant would be IMPORTANT to me.',
        'Purchasing food from this halal-certified restaurant would be VALUABLE to me.',
        'Buying food from this halal-certified restaurant would be FAVORABLE to me.'
    ];
    const q2SN = [
        'Most people whose opinions I value would APPROVE of me purchasing food from this halal-certified restaurant.',
        'Most people who are IMPORTANT to me think I should purchase food from this halal-certified restaurant.',
        'Most people who influence my behavior believe I should buy food from this halal-certified restaurant.',
        'People in my life whose opinions I value would PREFER that I purchase food from this halal-certified restaurant.',
        'Most people I RESPECT AND ADMIRE would purchase food from this halal-certified restaurant.'
    ];
    const q2PBC = [
        'For me, purchasing food from this halal-certified restaurant would be EASY.',
        'If I wanted to, I COULD purchase food from this halal-certified restaurant.',
        'I am CONFIDENT that I can purchase food from this halal-certified restaurant.',
        'It is ENTIRELY UP TO ME whether or not to purchase food from this halal-certified restaurant.',
        'I have complete CONTROL over purchasing food from this halal-certified restaurant.'
    ];
    
    const questions = questionnaireSet === 1 
        ? {title: 'Customer Demand Survey (Muslim Customer)', ATT: q1ATT, SN: q1SN, PBC: q1PBC}
        : {title: 'Customer Demand Survey (Non-Muslim Customer)', ATT: q2ATT, SN: q2SN, PBC: q2PBC};
    
    const scaleLabels = ['Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree'];
    
    let html = '<div style="background:#fff;border-radius:16px;max-width:780px;width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
    html += '<div style="padding:20px 24px;border-bottom:2px solid #e5e7eb;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;">';
    html += '<h3 style="margin:0;font-size:1.3rem;font-weight:800;">' + questions.title + '</h3>';
    html += '<p style="margin:6px 0 0;font-size:.82rem;opacity:.9;"><i class="fas fa-clipboard-check"></i> Please complete this quick survey before placing your order</p>';
    html += '</div>';
    
    html += '<div style="padding:24px;overflow-y:auto;flex:1;">';
    html += '<div style="background:#fff3cd;border:1.5px solid #ffc107;border-radius:10px;padding:14px;margin-bottom:20px;">';
    html += '<div style="display:flex;gap:10px;"><i class="fas fa-info-circle" style="color:#f59e0b;font-size:1.2rem;"></i>';
    html += '<div style="font-size:.82rem;color:#856404;"><strong>Required Survey:</strong> This anonymous survey helps us understand customer demand patterns. You only need to complete this once.</div></div></div>';
    
    html += '<form id="survey-form"><input type="hidden" name="questionnaire_set" value="' + questionnaireSet + '">';
    
    ['ATT', 'SN', 'PBC'].forEach((construct, ci) => {
        const label = construct === 'ATT' ? 'Attitude (ATT)' : construct === 'SN' ? 'Subjective Norms (SN)' : 'Perceived Behavioral Control (PBC)';
        html += '<div style="margin-bottom:28px;">';
        html += '<h4 style="font-size:1rem;font-weight:700;color:#6366f1;margin-bottom:16px;display:flex;align-items:center;gap:8px;">';
        html += '<div style="width:28px;height:28px;background:#6366f1;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;">' + (ci + 1) + '</div>';
        html += label + '</h4>';
        
        questions[construct].forEach((q, qi) => {
            html += '<div style="background:#f8fafc;border:1.5px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px;">';
            html += '<div style="font-weight:600;font-size:.88rem;color:#374151;margin-bottom:12px;">' + construct + (qi + 1) + '. ' + escapeHtml(q) + '</div>';
            html += '<div style="display:flex;justify-content:space-between;gap:4px;">';
            
            for (let v = 1; v <= 5; v++) {
                html += '<label style="flex:1;text-align:center;cursor:pointer;">';
                html += '<input type="radio" name="' + construct + (qi + 1) + '" value="' + v + '" required style="display:none;" onchange="highlightSurveyOption(this)">';
                html += '<div class="survey-option" style="padding:10px 6px;border:2px solid #d1d5db;border-radius:8px;transition:all .2s;font-size:.8rem;font-weight:600;color:#6b7280;">';
                html += '<div style="font-size:1.1rem;margin-bottom:2px;">' + v + '</div>';
                html += '<div style="font-size:.65rem;text-transform:uppercase;">' + scaleLabels[v - 1] + '</div>';
                html += '</div></label>';
            }
            
            html += '</div></div>';
        });
        
        html += '</div>';
    });
    
    html += '</form></div>';
    
    html += '<div style="padding:16px 24px;border-top:2px solid #e5e7eb;background:#fafafa;display:flex;gap:10px;justify-content:flex-end;">';
    html += '<button type="button" onclick="closeSurveyModal()" style="padding:12px 24px;border:2px solid #d1d5db;background:#fff;color:#374151;border-radius:10px;font-weight:700;cursor:pointer;">Cancel</button>';
    html += '<button type="button" onclick="submitSurvey()" style="padding:12px 32px;border:none;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-radius:10px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(99,102,241,0.3);"><i class="fas fa-check-circle"></i> Submit & Continue</button>';
    html += '</div></div>';
    
    modal.innerHTML = html;
    document.body.appendChild(modal);
}

function highlightSurveyOption(radio) {
    document.querySelectorAll('input[name="' + radio.name + '"]').forEach(inp => {
        const div = inp.nextElementSibling;
        div.style.borderColor = '#d1d5db';
        div.style.background = '#fff';
        div.style.color = '#6b7280';
    });
    
    const div = radio.nextElementSibling;
    div.style.borderColor = '#6366f1';
    div.style.background = '#ede9fe';
    div.style.color = '#4f46e5';
}

function closeSurveyModal() {
    const modal = document.getElementById('survey-modal-overlay');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => modal.remove(), 200);
    }
}

async function submitSurvey() {
    const form = document.getElementById('survey-form');
    const formData = new FormData(form);
    
    const required = ['ATT1', 'ATT2', 'ATT3', 'ATT4', 'ATT5', 'SN1', 'SN2', 'SN3', 'SN4', 'SN5', 'PBC1', 'PBC2', 'PBC3', 'PBC4', 'PBC5'];
    const missing = required.filter(q => !formData.get(q));
    
    if (missing.length > 0) {
        alert('Please answer all questions. Missing: ' + missing.join(', '));
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    try {
        const response = await fetch(BASE_URL + 'api/submit_survey.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            closeSurveyModal();
            if (typeof showCartToast === 'function') {
                showCartToast('Survey completed! Proceeding to checkout...', false);
            }
            setTimeout(() => {
                if (typeof showCheckoutModal === 'function') {
                    showCheckoutModal();
                }
            }, 500);
        } else {
            alert(data.message || 'Failed to submit survey. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit & Continue';
        }
    } catch (error) {
        console.error('Survey submission error:', error);
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit & Continue';
    }
}

// Helper function if not already defined
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
