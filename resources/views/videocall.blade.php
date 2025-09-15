<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>1-to-1 Video Call (Janus)</title>

  <!-- Required scripts (provided in challenge) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/webrtc-adapter/8.2.3/adapter.min.js"></script>
  <script src="https://janus.conf.meetecho.com/demos/janus.js"></script>

  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    #video-container { display:flex; gap:20px; }
    .video-box { flex:1; border:1px solid #ccc; border-radius:6px; overflow:hidden; }
    .video-header { padding:6px; background:#f0f0f0; border-bottom:1px solid #ccc;
                    display:flex; justify-content:space-between; align-items:center; }
    video { width:100%; height:360px; background:black; object-fit:cover; }
    #controls { margin-bottom:12px; }
    #logs { margin-top:12px; white-space:pre-wrap; background:#f8f8f8; padding:8px; 
            border-radius:6px; max-height:200px; overflow-y:auto; font-size:13px; }
    button { margin-left:6px; }
    .badge { padding:4px 8px; border-radius:4px; color:#fff; font-size:12px; margin-left:6px; }
    .badge-info { background:#17a2b8; }
    .badge-primary { background:#007bff; }
  </style>
</head>
<body>
  <h2>Janus 1-to-1 Video Call — Laravel + Vanilla JS</h2>

  <div id="controls">
    <label>Room ID: <input id="room-id" type="number" value="1234" /></label>
    <button id="start-btn">Start Session</button>
    <span style="margin-left:12px;">Role: <strong id="role-display">--</strong></span>
    <span id="status" style="margin-left:12px; font-weight:600;">Status: idle</span>
  </div>

  <!-- Left = Presenter (local); Right = Viewer (remote) -->
  <div id="video-container">
    <div class="video-box">
      <div class="video-header">
        <span><strong>Presenter (Local)</strong></span>
        <div>
          <button id="audio-btn">Disable audio</button>
          <button id="video-btn">Disable video</button>
        </div>
      </div>
      <video id="localVideo" autoplay muted></video>
    </div>

    <div class="video-box">
      <div class="video-header">
        <span><strong>Viewer (Remote)</strong></span>
        <div>
          <span id="remote-resolution" class="badge badge-info">--</span>
          <span id="remote-bitrate" class="badge badge-primary">--</span>
        </div>
      </div>
      <video id="remoteVideo" autoplay playsinline></video>
    </div>
  </div>

  <div id="logs"></div>

<script>

/* ---------- Config ---------- */
const JANUS_SERVERS = [
  'wss://dev-live-cast-wrtc.transperfect.com:8089/janus',
  'https://dev-live-cast-wrtc.transperfect.com:8089/janus'
];
// DOM
const startBtn = document.getElementById('start-btn');
const audioBtn = document.getElementById('audio-btn');
const videoBtn = document.getElementById('video-btn');
const roomInput = document.getElementById('room-id');
const localVideo = document.getElementById('localVideo');
const remoteVideo = document.getElementById('remoteVideo');
const roleDisplay = document.getElementById('role-display');
const statusSpan = document.getElementById('status');
const logs = document.getElementById('logs');
const resLabel = document.getElementById('remote-resolution');
const brLabel = document.getElementById('remote-bitrate');

/* ---------- State ---------- */
let janus = null;
let mainHandle = null;     
let subscriberHandle = null; 
let myStream = null;
let remoteStream = new MediaStream();
let room = parseInt(roomInput.value) || 1234;
const role = "{{ $role ?? '' }}" || window.location.pathname.split('/').pop() || 'viewer';
let audioEnabled = true, videoEnabled = true;
let bitrateTimer = null, resolutionTimer = null;
const opaqueId = "videoroom-" + Janus.randomString(6);

/* ---------- UI helpers ---------- */
roleDisplay.innerText = role.charAt(0).toUpperCase() + role.slice(1);
function log(msg){ logs.textContent = new Date().toLocaleTimeString() + ' — ' + msg + "\n" + logs.textContent; }
function setStatus(s){ statusSpan.innerText = 'Status: ' + s; log('[Status] ' + s); }
function attachMedia(el, stream, mute=false){
  if(!el) return;
  el.srcObject = stream;
  el.muted = mute;
  el.playsInline = true;
  el.autoplay = true;
  el.play().catch(e => console.warn('Autoplay prevented:', e));
}

/* ---------- Presenter (publisher) ---------- */
function startPresenterPublish(){
  setStatus('Requesting local media...');
  navigator.mediaDevices.getUserMedia({ audio: true, video: true })
    .then(stream => {
      myStream = stream;
      attachMedia(localVideo, myStream, true);
      // createOffer then publish
      mainHandle.createOffer({
        media: { audioRecv:false, videoRecv:false, audioSend:true, videoSend:true },
        stream: myStream,
        success: function(jsep) {
          setStatus('Publishing to Janus...');
          mainHandle.send({ message: { request: 'publish', audio: true, video: true }, jsep: jsep });
        },
        error: function(err) {
          log('createOffer error: ' + err);
        }
      });
    })
    .catch(err => {
      log('getUserMedia error: ' + err);
      setStatus('Media error');
    });
}

/* ---------- Viewer (subscriber) ---------- */
function createSubscriberAndSubscribe(feedId){
  setStatus('Attaching subscriber...');
  janus.attach({
    plugin: "janus.plugin.videoroom",
    opaqueId: opaqueId + "-sub",
    success: function(handle) {
      subscriberHandle = handle;
      // join as subscriber to feedId
      const join = { request: "join", room: room, ptype: "subscriber", feed: feedId };
      subscriberHandle.send({ message: join });
    },
    error: function(err){ log('Subscriber attach error: ' + err); },
    onmessage: function(msg, jsep) {
      log('Subscriber message: ' + JSON.stringify(msg));
      if (jsep) {
        // Create answer (recvonly)
        subscriberHandle.createAnswer({
          jsep: jsep,
          media: { audioSend:false, videoSend:false },
          success: function(jsepAnswer) {
            subscriberHandle.send({ message: { request: "start", room: room }, jsep: jsepAnswer });
            startRemoteStats();
          },
          error: function(err) { log('subscriber createAnswer error: ' + err); }
        });
      }
    },
    onremotetrack: function(track, mid, added) {
      if (added) remoteStream.addTrack(track);
      attachMedia(remoteVideo, remoteStream, false);
    },
    oncleanup: function() {
      log('Subscriber cleaned up');
      if(remoteVideo) remoteVideo.srcObject = null;
      remoteStream = new MediaStream();
      stopRemoteStats();
    }
  });
}

/* ---------- Janus session & main plugin attach ---------- */
function startSession() {
  room = parseInt(roomInput.value) || 1234;
  setStatus('Initializing Janus...');
  Janus.init({
    debug: "all",
    callback: function() {
      if(!Janus.isWebrtcSupported()) {
        alert('WebRTC not supported');
        return;
      }
      janus = new Janus({
        server: JANUS_SERVERS,
        success: function() {
          setStatus('Connected to Janus, attaching VideoRoom plugin...');
          janus.attach({
            plugin: "janus.plugin.videoroom",
            opaqueId: opaqueId,
            success: function(handle) {
              mainHandle = handle;
              setStatus('Attached main handle.');
              // Role-specific flow:
              if (role === 'presenter') {
                mainHandle.send({ message: { request: 'create', room: room, publishers: 1 } });
                mainHandle.send({ message: { request: 'join', room: room, ptype: 'publisher', display: 'Presenter' } });
              } else {
                mainHandle.send({ message: { request: 'join', room: room, ptype: 'publisher', display: 'Viewer' } });
              }
            },
            error: function(err){ setStatus('Attach error: ' + err); },
            onmessage: function(msg, jsep) {
              log('Main handle message: ' + JSON.stringify(msg));
              const event = msg['videoroom'];
              if(event === 'joined') {
                // joined room: if presenter => publish; if viewer => check publishers, subscribe
                if (role === 'presenter') {
                  startPresenterPublish();
                } else {
                  // Viewer: if publishers present, subscribe to first one (1-to-1)
                  const publishers = msg.publishers || [];
                  if (publishers.length > 0) {
                    const feedId = publishers[0].id;
                    createSubscriberAndSubscribe(feedId);
                  } else {
                    setStatus('No publisher found yet — waiting...');
                  }
                }
              } else if (event === 'event' && msg.publishers) {
                // New publisher appeared: if viewer and not subscribed yet, subscribe to the first publisher.
                if (role !== 'presenter' && !subscriberHandle && msg.publishers.length > 0) {
                  createSubscriberAndSubscribe(msg.publishers[0].id);
                }
              } else if (event === 'error') {
                log('Room error: ' + JSON.stringify(msg));
              }
              if (jsep && role === 'presenter') {
                mainHandle.handleRemoteJsep({ jsep: jsep });
              }
            },
            onlocalstream: function(stream) {
              log('onlocalstream');
            },
            onremotestream: function(stream) {
              log('onremotestream main handle (ignored)');
            },
            oncleanup: function() {
              log('Main handle cleaned up');
            }
          });
        },
        error: function(err) {
          setStatus('Janus connection error: ' + JSON.stringify(err));
          log('Janus connect error: ' + JSON.stringify(err));
        },
        destroyed: function() {
          setStatus('Janus destroyed');
        }
      });
    }
  });
}

/* ---------- Remote stats (bitrate/resolution) ---------- */
function startRemoteStats(){
  stopRemoteStats();
  bitrateTimer = setInterval(() => {
    try {
      if (subscriberHandle && subscriberHandle.getBitrate) {
        const b = subscriberHandle.getBitrate() || '--';
        brLabel.innerText = b;
      }
    } catch(e){ /* ignore */ }
  }, 1000);

  resolutionTimer = setInterval(() => {
    if (remoteVideo && remoteVideo.videoWidth) {
      resLabel.innerText = remoteVideo.videoWidth + 'x' + remoteVideo.videoHeight;
    }
  }, 1000);
}
function stopRemoteStats(){
  if (bitrateTimer) { clearInterval(bitrateTimer); bitrateTimer = null; }
  if (resolutionTimer) { clearInterval(resolutionTimer); resolutionTimer = null; }
}

startBtn.addEventListener('click', () => {
  startBtn.disabled = true;
  startSession();
});

audioBtn.addEventListener('click', () => {
  if (!myStream) { setStatus('No local stream'); return; }
  audioEnabled = !audioEnabled;
  myStream.getAudioTracks().forEach(t => t.enabled = audioEnabled);
  audioBtn.innerText = audioEnabled ? 'Disable audio' : 'Enable audio';
  if (mainHandle && mainHandle.send) {
    mainHandle.send({ message: { request: 'configure', audio: audioEnabled }});
  }
});

videoBtn.addEventListener('click', () => {
  if (!myStream) { setStatus('No local stream'); return; }
  videoEnabled = !videoEnabled;
  myStream.getVideoTracks().forEach(t => t.enabled = videoEnabled);
  videoBtn.innerText = videoEnabled ? 'Disable video' : 'Enable video';
  if (mainHandle && mainHandle.send) {
    mainHandle.send({ message: { request: 'configure', video: videoEnabled }});
  }
});

/* ---------- Clean up before unload ---------- */
window.addEventListener('beforeunload', () => {
  try {
    if (myStream) myStream.getTracks().forEach(t => t.stop());
    if (janus) janus.destroy();
  } catch(e){}
});
</script>
</body>
</html>
