// QR Code Scanner JavaScript
let html5QrCode;
let currentParticipant = null;
let selectedGuestCount = 1;
let isScanning = false;

// Get base path from global variable
const basePath = window.APP_BASE_PATH || '';

document.addEventListener('DOMContentLoaded', function() {
    const startButton = document.getElementById('startButton');
    const stopButton = document.getElementById('stopButton');
    const manualForm = document.getElementById('manualForm');
    
    if (startButton) {
        startButton.addEventListener('click', startScanner);
    }
    
    if (stopButton) {
        stopButton.addEventListener('click', stopScanner);
    }
    
    if (manualForm) {
        manualForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const manualId = document.getElementById('manualId').value.trim();
            if (manualId) {
                onQRCodeScanned(manualId);
            }
        });
    }
});

async function startScanner() {
    try {
        // Check if we're in a secure context (HTTPS or localhost)
        if (!window.isSecureContext) {
            const currentHost = window.location.hostname;
            let errorMsg = 'Camera access requires a secure connection (HTTPS) or localhost.\n\n';
            if (currentHost !== 'localhost' && currentHost !== '127.0.0.1') {
                errorMsg += 'You are currently accessing via: ' + currentHost + '\n\n';
                errorMsg += 'Please try one of these options:\n';
                errorMsg += '1. Access via localhost\n';
                errorMsg += '2. Enable HTTPS in your server\n';
                errorMsg += '3. Use the manual entry option below';
            }
            updateScannerStatus('⚠️ Secure context required for camera', 'error');
            alert(errorMsg);
            return;
        }

        // Check if getUserMedia is available
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            updateScannerStatus('⚠️ Camera not supported in this browser', 'error');
            alert('Camera access is not supported in this browser. Please use a modern browser or try the manual entry option.');
            return;
        }

        const video = document.getElementById('video');
        const placeholder = document.getElementById('scannerPlaceholder');
        const overlay = document.getElementById('scanner-overlay');
        const container = document.getElementById('scanner-container');
        
        // Request camera access
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' }
        });
        
        video.srcObject = stream;
        video.play();
        
        // Show video, hide placeholder
        if (placeholder) placeholder.style.display = 'none';
        video.classList.add('active');
        if (overlay) overlay.classList.add('active');
        if (container) container.classList.add('active');
        
        // Initialize QR code scanner
        html5QrCode = new Html5Qrcode("scanner-container");
        
        const config = { 
            fps: 10,
            qrbox: { width: 200, height: 200 }
        };
        
        await html5QrCode.start(
            { facingMode: "environment" },
            config,
            onScanSuccess,
            onScanFailure
        );
        
        isScanning = true;
        document.getElementById('startButton').style.display = 'none';
        document.getElementById('stopButton').style.display = 'inline-flex';
        updateScannerStatus('Scanning...', 'scanning');
        
    } catch (error) {
        console.error('Error starting scanner:', error);
        
        let errorMessage = 'Unable to access camera.\n\n';
        
        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            errorMessage += 'Camera permission was denied.\n\n';
            errorMessage += 'To fix this:\n';
            errorMessage += '1. Click the camera icon in the address bar (or the lock icon)\n';
            errorMessage += '2. Select "Allow" for camera access\n';
            errorMessage += '3. Refresh the page and try again\n\n';
            errorMessage += 'Or go to browser settings:\n';
            errorMessage += 'Chrome: Settings → Privacy → Site Settings → Camera\n';
            errorMessage += 'Edge: Settings → Cookies and site permissions → Camera';
            updateScannerStatus('⚠️ Camera permission denied - click the camera/lock icon in address bar to allow', 'error');
        } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
            errorMessage += 'No camera found on this device.\n';
            errorMessage += 'Please connect a camera or use the manual entry option.';
            updateScannerStatus('⚠️ No camera found', 'error');
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            errorMessage += 'Camera is already in use by another application.\n';
            errorMessage += 'Please close other apps using the camera and try again.';
            updateScannerStatus('⚠️ Camera in use by another app', 'error');
        } else {
            errorMessage += 'Error: ' + error.message;
            updateScannerStatus('⚠️ Camera error: ' + error.message, 'error');
        }
        
        alert(errorMessage);
    }
}

async function stopScanner() {
    if (html5QrCode && isScanning) {
        try {
            await html5QrCode.stop();
            const video = document.getElementById('video');
            const placeholder = document.getElementById('scannerPlaceholder');
            const overlay = document.getElementById('scanner-overlay');
            const container = document.getElementById('scanner-container');
            
            if (video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
            
            // Hide video, show placeholder
            video.classList.remove('active');
            if (overlay) overlay.classList.remove('active');
            if (container) container.classList.remove('active');
            if (placeholder) placeholder.style.display = 'block';
            
            isScanning = false;
            document.getElementById('startButton').style.display = 'inline-flex';
            document.getElementById('stopButton').style.display = 'none';
            updateScannerStatus('Scanner stopped', 'error');
        } catch (error) {
            console.error('Error stopping scanner:', error);
        }
    }
}

function onScanSuccess(decodedText, decodedResult) {
    if (!isScanning) return;
    
    // Stop scanning temporarily
    stopScanner();
    
    // Process the QR code
    onQRCodeScanned(decodedText);
}

function onScanFailure(error) {
    // Ignore scan failures (too noisy)
}

async function onQRCodeScanned(qrData) {
    try {
        showLoading('Verifying ticket...');
        
        const response = await fetch(basePath + '/api/verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ qr_data: qrData })
        });
        
        const result = await response.json();
        hideLoading();
        
        if (!result.success) {
            updateScannerStatus('❌ ' + result.message, 'error');
            playBeep('error');
            setTimeout(() => {
                if (!isScanning) startScanner();
            }, 3000);
            return;
        }
        
        currentParticipant = result.data;
        showCheckinModal(result);
        playBeep('success');
        
    } catch (error) {
        hideLoading();
        console.error('Verification error:', error);
        updateScannerStatus('System error: ' + error.message, 'error');
        playBeep('error');
    }
}

function showCheckinModal(result) {
    const modal = document.getElementById('checkinModal');
    const data = result.data;
    
    // Set participant info
    document.getElementById('participantName').textContent = data.name;
    document.getElementById('participantId').textContent = data.unique_id;
    document.getElementById('ticketType').textContent = data.ticket_type;
    document.getElementById('eventName').textContent = data.event_name;
    
    // Update progress bar
    const progress = (data.guests_checked_in / data.total_guests) * 100;
    document.getElementById('progressFill').style.width = progress + '%';
    document.getElementById('guestsChecked').textContent = data.guests_checked_in;
    document.getElementById('totalGuests').textContent = data.total_guests;
    
    const statusBadge = document.getElementById('statusBadge');
    const guestSelection = document.getElementById('guestSelection');
    const actionButtons = document.getElementById('actionButtons');
    const alreadyCheckedMsg = document.getElementById('alreadyCheckedMsg');
    const historyDiv = document.getElementById('checkinHistory');
    
    switch (result.status) {
        case 'valid':
            statusBadge.className = 'status-badge status-valid';
            statusBadge.innerHTML = '<i class="fas fa-check-circle"></i> VALID TICKET';
            showGuestSelection(data.total_guests);
            actionButtons.style.display = 'flex';
            alreadyCheckedMsg.style.display = 'none';
            historyDiv.style.display = 'none';
            break;
            
        case 'partial_checked':
            statusBadge.className = 'status-badge status-partial';
            statusBadge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> PARTIAL CHECK-IN';
            showGuestSelection(data.guests_remaining);
            actionButtons.style.display = 'flex';
            alreadyCheckedMsg.style.display = 'none';
            showCheckinHistory(data.checkin_history);
            break;
            
        case 'fully_checked':
            statusBadge.className = 'status-badge status-complete';
            statusBadge.innerHTML = '<i class="fas fa-ban"></i> ALREADY CHECKED IN';
            guestSelection.style.display = 'none';
            actionButtons.style.display = 'none';
            alreadyCheckedMsg.style.display = 'block';
            document.getElementById('lastCheckinTime').textContent = formatDateTime(data.last_checkin_at);
            showCheckinHistory(data.checkin_history);
            break;
    }
    
    modal.classList.add('active');
}

function showGuestSelection(maxGuests) {
    const container = document.getElementById('guestButtons');
    const guestSelection = document.getElementById('guestSelection');
    
    container.innerHTML = '';
    selectedGuestCount = 1;
    
    if (maxGuests === 1) {
        guestSelection.style.display = 'none';
        return;
    }
    
    guestSelection.style.display = 'block';
    
    for (let i = 1; i <= maxGuests; i++) {
        const btn = document.createElement('button');
        btn.className = 'guest-btn' + (i === 1 ? ' selected' : '');
        btn.textContent = i;
        btn.type = 'button';
        btn.onclick = () => selectGuestCount(i);
        container.appendChild(btn);
    }
}

function selectGuestCount(count) {
    selectedGuestCount = count;
    
    document.querySelectorAll('.guest-btn').forEach(btn => {
        btn.classList.remove('selected');
        if (parseInt(btn.textContent) === count) {
            btn.classList.add('selected');
        }
    });
}

function showCheckinHistory(history) {
    const historyDiv = document.getElementById('checkinHistory');
    const historyList = document.getElementById('historyList');
    
    if (!history || history.length === 0) {
        historyDiv.style.display = 'none';
        return;
    }
    
    historyList.innerHTML = '';
    history.forEach(item => {
        const li = document.createElement('li');
        li.innerHTML = `
            <span><strong>${item.guests_this_checkin}</strong> guest(s)</span>
            <span>${formatDateTime(item.created_at)}</span>
        `;
        historyList.appendChild(li);
    });
    
    historyDiv.style.display = 'block';
}

async function confirmCheckin() {
    if (!currentParticipant) return;
    
    try {
        showLoading('Processing check-in...');
        
        const response = await fetch(basePath + '/api/checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                unique_id: currentParticipant.unique_id,
                guests_count: selectedGuestCount,
                gate_location: 'Main Entrance'
            })
        });
        
        const result = await response.json();
        hideLoading();
        
        if (result.success) {
            // Show success state
            const statusBadge = document.getElementById('statusBadge');
            statusBadge.className = 'status-badge status-success';
            statusBadge.innerHTML = '<i class="fas fa-check-circle"></i> ' + result.message;
            
            document.getElementById('actionButtons').style.display = 'none';
            document.getElementById('guestSelection').style.display = 'none';
            
            // Update progress
            const progress = (result.data.guests_checked_in / result.data.total_guests) * 100;
            document.getElementById('progressFill').style.width = progress + '%';
            document.getElementById('guestsChecked').textContent = result.data.guests_checked_in;
            
            playBeep('checkin');
            updateScannerStatus('✅ Check-in successful!', 'success');
            
            // Auto close and restart scanner
            setTimeout(() => {
                closeModal();
                startScanner();
            }, 3000);
        } else {
            alert('Check-in failed: ' + result.message);
            playBeep('error');
        }
        
    } catch (error) {
        hideLoading();
        alert('System error: ' + error.message);
        playBeep('error');
    }
}

function closeModal() {
    document.getElementById('checkinModal').classList.remove('active');
    currentParticipant = null;
    selectedGuestCount = 1;
    
    // Clear manual input
    document.getElementById('manualId').value = '';
}

function updateScannerStatus(message, type) {
    const status = document.getElementById('scannerStatus');
    if (status) {
        status.className = 'scanner-status ' + type + ' active';
        status.textContent = message;
        
        // Auto-hide after 3 seconds for success/error
        if (type === 'success' || type === 'error') {
            setTimeout(() => {
                status.classList.remove('active');
            }, 3000);
        }
    }
}

function formatDateTime(datetime) {
    if (!datetime) return 'N/A';
    const d = new Date(datetime);
    return d.toLocaleString();
}

function playBeep(type) {
    const context = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    
    oscillator.connect(gainNode);
    gainNode.connect(context.destination);
    
    if (type === 'success' || type === 'checkin') {
        oscillator.frequency.value = 800;
        gainNode.gain.value = 0.3;
        oscillator.start();
        setTimeout(() => oscillator.stop(), 100);
    } else {
        oscillator.frequency.value = 400;
        gainNode.gain.value = 0.3;
        oscillator.start();
        setTimeout(() => oscillator.stop(), 200);
    }
}

// Close modal when clicking outside
document.getElementById('checkinModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
