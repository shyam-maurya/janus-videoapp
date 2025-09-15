# Janus 1-to-1 Video Call (Laravel + JavaScript)

This is a small demo web application that shows how to build a 1-to-1 video call using:

- Laravel (PHP) as backend
- Vanilla JavaScript as frontend
- Janus WebRTC Server as the signaling/media server

---

## Architecture Overview

- ##Janus Server
  Handles WebRTC signaling and relays media. We use the official Janus VideoRoom plugin.
  
- ##Laravel Backend
Serves the frontend view (`videocall.blade.php`) and routes:

- `/videocall/presenter`  
- `/videocall/viewer`  

- Frontend (JS) 
The app uses two scripts loaded via CDN:
```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/webrtc-adapter/8.2.3/adapter.min.js"></script>
<script src="https://janus.conf.meetecho.com/demos/janus.js"></script>

- UI Layout
  Two side-by-side video boxes:
  - Left: Presenter’s own video preview (local camera).
  - Right: Viewer’s subscribed stream (remote).

---

## Local Setup

1. Clone the project and install dependencies:
   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate

2. Run Laravel’s development server:

      php artisan serve

3. Open two tabs (or two browsers):

    Presenter: http://127.0.0.1:8000/videocall/presenter

    Viewer: http://127.0.0.1:8000/videocall/viewer