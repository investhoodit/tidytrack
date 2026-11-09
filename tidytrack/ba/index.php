<?php
session_start();
$error = $_SESSION['ba_login_error'] ?? '';
unset($_SESSION['ba_login_error']);
?>
<!doctype html>
<html lang="en"><head><script>window["__codeletBootstrap__"]=JSON.parse('{"A":"A","B":"20261012-04-4942f96","C":{"Abril Fatface":"YACgEZbkUVE,0","Albert Sans":"YAHO2HRfeh4,0","Alfa Slab One":"YACgEYS9sJU,0","Anton":"YACgEcYqQ-A,0","Archivo Black":"YACgEZulLoA,0","Archivo":"YAHO2-t-jNE,0","Arial":"YAGyDvJ_4Ts,0","Baloo 2":"YAHO2IcQUcM,0","Barlow Condensed":"YAFdJljEuFc,0","Barlow":"YAFdJsyuOPM,1","Bebas Neue":"YACgESME5ew,0","Bricolage Grotesque":"YAFyMcdwzpc,0","Canva Sans":"YAFdJjTk5UU,1","Caveat":"YALBs2ploWQ,0","Cinzel":"YACgEeV8bIQ,0","Cormorant Garamond":"YAFdJhX-538,0","Courier New":"YAGzXiGs0_8,0","Courier Prime":"YAD1aFjegN8,0","DM Sans":"YAD1aU3sLnI,0","DM Serif Display":"YAD1aYG82rc,0","Figtree":"YAHO2b-mV7A,0","Forum":"YACgEcnnqB4,0","Fraunces":"YAFdJpU7Kkk,0","Fredoka":"YACgEZml080,0","Georgia":"YAGzXkO0pEM,0","Hanken Grotesk":"YAHO2VB9G0I,0","Helvetica":"YAFcf7zkXN0,0","IBM Plex Mono":"YAFdJkmvhGI,0","IBM Plex Sans":"YAFdJgT0SHI,0","Impact":"YAFcfnjI7Vk,0","Instrument Sans":"YAHO2bKyaSQ,0","Instrument Serif":"YAHFeOnZNEk,0","Inter":"YAFdJvSyp_k,3","Italiana":"YAHO2Toik_w,0","Jacques Francois":"YAHO2a5g66Q,0","JetBrains Mono":"YAFdJksXcAk,0","Josefin Sans":"YAFdJjvw9Ps,0","Karla":"YAHO2V2NSXM,0","Lato":"YAFdJuFCnaw,0","Libre Baskerville":"YACgEUFdPdA,0","Libre Caslon Display":"YAHO2XhLmt0,0","Lobster":"YAD8SnSjSFI,0","Lora":"YACgEXvxf8Q,0","Manrope":"YAHO2b2feC4,0","Merriweather":"YACgEXvHxxs,0","Montserrat":"YAFdtQi73Xs,0","Noto Sans JP":"YAFdJiHXlcI,0","Noto Sans KR":"YAGsojZj-RQ,0","Nunito Sans":"YAFdtQJYB9w,1","Nunito":"YAFdJoNRMmU,1","Oleo Script":"YACgEQQ14jI,0","Oswald":"YACgEQY10lw,0","Outfit":"YAHO2nVlg14,0","Pacifico":"YADLjORQ1u4,0","Playfair Display":"YAFdJhem5V8,1","Plus Jakarta Sans":"YAHO2gdhi18,0","Poppins":"YAFdJjbTu24,1","Press Start 2P":"YAFyGr-8pmQ,0","Public Sans":"YAFdJj8NdaU,1","Quicksand":"YAFdJpYtCxE,1","Raleway":"YAFdJhmxbVQ,1","Roboto Slab":"YAFdJswIKAg,0","Rockwell":"YAFcfyVZnN0,0","Rye":"YACgEdQP21k,0","Sarabun":"YAFdJssSnME,0","Schibsted Grotesk":"YAHO2sWydRk,0","Segoe UI":"YAHNdRD1Klw,0","Source Sans 3":"YAG4lO1Mj10,0","Space Grotesk":"YAHO2sjPCSM,0","Space Mono":"YADW1-vpsoc,0","Spectral":"YAHO2rVUHIM,0","Times New Roman":"YAGzXW3gftg,0","Trebuchet MS":"YAFcfrglpVA,0","Ubuntu":"YACgERDU--Q,0","Verdana":"YAGzXTctYtQ,0","Work Sans":"YAGXhLOKv44,0","Yellowtail":"YACgEYG4kG4,0"}}');</script><script src="/_sdk/486a2977e39ad380.telemetry_sdk.js" integrity="sha512-6RGhG0lZ6QriXSp9fyZ35krENb9O9A9i6UklGaEPAGRJqlg443gszr5+rhcE8tJU48EjVhUuSuTu4g7tRHuFHA=="></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TidyTrack — Business Analyst Workspace</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Work+Sans:wght@500;600;700;800&amp;display=swap" rel="stylesheet">
  <style>
    :root {
      --ink:#172d3a;
      --muted:#586975;
      --teal:#176e70;
      --line:#d9e0de;
      --soft:#f6f7f4;
    }
    * { box-sizing:border-box; }
    body { margin:0; font-family:"DM Sans",sans-serif; color:var(--ink); }
    h1,h2,p { margin:0; }
    .app-shell { width:100%; min-height:calc(100 * min(var(--vh, 1vh), 1vh)); }
    .layout {
      width:100%; max-width:1600px; margin:0 auto;
      min-height:calc(100 * min(var(--vh, 1vh), 1vh));
      display:grid; grid-template-columns:minmax(0,1.06fr) minmax(390px,.94fr);
    }
    .brand-side {
      min-width:0; display:flex; flex-direction:column;
      padding:clamp(30px,4vw,60px) clamp(30px,5.4vw,88px) 32px;
      border-right:1px solid var(--line); background:var(--soft);
    }
    .wordmark { display:flex; align-items:center; gap:11px; font-family:"Work Sans",sans-serif; font-weight:700; letter-spacing:-.045em; }
    .brand-mark { width:34px; height:34px; border-radius:8px; display:grid; place-items:center; background:#176e70; color:#fff; }
    .brand-copy { max-width:640px; margin-top:clamp(53px,8vw,112px); }
    .eyebrow,.access-topline { color:var(--teal); font-size:11px; font-weight:700; letter-spacing:.13em; }
    .headline { max-width:680px; margin:18px 0 19px; font-family:"Work Sans",sans-serif; font-size:clamp(39px,4vw,62px); line-height:1.12; letter-spacing:-.055em; font-weight:700; }
    .intro { max-width:550px; color:var(--muted); font-size:16px; line-height:1.7; }
    .features { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:17px 22px; max-width:570px; margin-top:29px; }
    .feature { display:flex; align-items:center; gap:10px; min-width:0; font-size:13px; font-weight:600; }
    .feature-icon { flex:none; display:grid; place-items:center; width:27px; height:27px; color:var(--teal); }
    .feature-icon svg { width:17px; height:17px; }
    .photo-frame { width:100%; max-width:625px; height:clamp(205px,21vw,290px); overflow:hidden; margin:38px 0 29px; border:1px solid var(--line); border-radius:10px; background:#e7ebe8; }
    .photo-frame img { width:100%; height:100%; display:block; object-fit:cover; object-position:center 48%; }
    .brand-side footer { margin-top:auto; }
    .copyright { color:#72808a; font-size:12px; }
    .access-side { display:flex; align-items:center; justify-content:center; padding:58px clamp(28px,5.5vw,88px); background:#fff; }
    .access-inner { width:100%; max-width:420px; }
    .access-topline { display:flex; align-items:center; gap:8px; margin-bottom:20px; }
    .access-topline svg { width:15px; height:15px; }
    .access-card { padding:clamp(26px,3.1vw,40px); background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 12px 36px rgba(23,45,58,.055); }
    .access-title { font-family:"Work Sans",sans-serif; font-size:32px; font-weight:700; letter-spacing:-.045em; line-height:1.2; }
    .access-description { margin-top:11px; color:var(--muted); font-size:14px; line-height:1.65; }
    .form-area { margin-top:30px; }
    label { display:block; margin-bottom:9px; font-size:13px; font-weight:700; }
    .input-wrap { position:relative; }
    .input-wrap > svg { position:absolute; top:50%; left:15px; width:18px; height:18px; transform:translateY(-50%); color:#788b92; pointer-events:none; }
    input { width:100%; height:50px; padding:0 14px 0 44px; color:var(--ink); background:#fff; border:1px solid #bfcacb; border-radius:8px; outline:none; transition:border-color .18s,box-shadow .18s; }
    input::placeholder { color:#87959b; }
    input:hover { border-color:#819b9c; }
    input:focus-visible { border-color:var(--teal); box-shadow:0 0 0 3px rgba(23,110,112,.14); }
    input[aria-invalid="true"] { border-color:#af4545; }
    .error { margin-top:8px; color:#a33535; font-size:12px; line-height:1.45; }
    .continue { display:flex; align-items:center; justify-content:center; gap:9px; width:100%; height:50px; margin-top:18px; color:#fff; background:#176e70; border:1px solid #176e70; border-radius:8px; font-weight:700; cursor:pointer; transition:background .18s,transform .18s; }
    .continue:hover { background:#115c5e; }
    .continue:active { transform:translateY(1px); }
    .continue:disabled { opacity:.76; cursor:wait; }
    .secondary { display:block; margin:17px auto 0; padding:7px 10px; color:var(--teal); background:none; border:0; border-radius:6px; font-weight:700; cursor:pointer; }
    .secondary:hover,.admin-button:hover { background:#edf5f3; }
    button:focus-visible { outline:3px solid #73b6ae; outline-offset:3px; }
    .security { display:flex; align-items:flex-start; gap:11px; margin-top:24px; padding:14px; background:#f7f9f7; border:1px solid #e3e9e5; border-radius:8px; }
    .shield { flex:none; margin-top:1px; color:var(--teal); }
    .security-title { color:var(--teal); font-size:10px; font-weight:800; letter-spacing:.11em; }
    .security-copy { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.55; }
    .support { margin-top:23px; color:var(--muted); text-align:center; font-size:12px; line-height:1.8; }
    .admin-button { padding:2px 4px; color:var(--teal); background:none; border:0; border-radius:4px; text-decoration:underline; text-underline-offset:3px; cursor:pointer; }
    .feedback { margin-top:16px; padding:13px; color:#245d4d; background:#f0f7f2; border:1px solid #cce2d2; border-radius:8px; font-size:13px; line-height:1.5; }
    .notice { display:flex; align-items:start; justify-content:space-between; gap:12px; margin-top:15px; padding:12px 12px 12px 14px; color:#355663; background:#f0f6f6; border:1px solid #c8dddc; border-radius:8px; font-size:12px; line-height:1.5; }
    .notice button { flex:none; padding:2px; color:#355663; background:none; border:0; border-radius:4px; cursor:pointer; }
    .spinner { width:16px; height:16px; border:2px solid #ffffff75; border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; }
    @keyframes spin { to { transform:rotate(360deg); } }
    
    .input-wrap .toggle-pass {
  position: absolute;
  top: 50%;
  right: 10px;
  transform: translateY(-50%);
  border: 0;
  background: none;
  color: #788b92;
  cursor: pointer;
  padding: 6px;
  display: grid;
  place-items: center;
}
.input-wrap input[type="password"],
.input-wrap input[type="text"]#company-password {
  padding-right: 44px;
}

.brand-logo {
  width: 34px;
  height: 34px;
  object-fit: contain;
  border-radius: 8px;
  display: block;
  flex-shrink: 0;
}
    
  @media (max-width: 900px) {
  .layout {
    display: flex;
    flex-direction: column;
    min-height: auto;
  }
  .access-side {
    order: 0;
    padding: 28px 20px 36px;
    align-items: flex-start;
  }
  .access-inner {
    max-width: 100%;
  }
  .access-title {
    font-size: 26px !important;
  }
  .brand-side {
    order: 1;
    border-right: 0;
    border-top: 1px solid var(--line);
    padding: 28px 20px 24px;
  }
  .brand-copy {
    margin-top: 28px;
  }
  .headline {
    font-size: clamp(28px, 7vw, 36px) !important;
    max-width: 100%;
  }
  .intro {
    font-size: 15px;
    max-width: 100%;
  }
  .features {
    grid-template-columns: 1fr 1fr;
    max-width: 100%;
    gap: 12px;
  }
  .photo-frame {
    height: 200px;
    max-width: 100%;
    margin: 24px 0 20px;
  }
  .photo-frame img {
    object-fit: cover;
  }
}

@media (max-width: 520px) {
  .access-side {
    padding: 20px 14px 28px;
  }
  .access-card {
    padding: 20px 16px !important;
    border-radius: 10px;
  }
  .access-title {
    font-size: 22px !important;
  }
  .access-description {
    font-size: 13px !important;
  }
  .brand-side {
    padding: 22px 14px 20px;
  }
  .headline {
    font-size: 26px !important;
    margin: 12px 0 12px;
  }
  .features {
    grid-template-columns: 1fr;
    gap: 10px;
  }
  .feature {
    font-size: 13px !important;
  }
  .photo-frame {
    height: 180px;
  }
  input {
    font-size: 16px !important; /* no iOS zoom */
    height: 48px;
  }
  .continue {
    height: 48px;
  }
  .wordmark span {
    font-size: 20px !important;
  }
}
    @media(prefers-reduced-motion:reduce) { *,*:before,*:after { animation-duration:.01ms!important; transition-duration:.01ms!important; } }
    
    
    /* Fix email and password input fields */
.input-wrap {
    position: relative;
    width: 100%;
}

.input-wrap > input {
    display: block;
    width: 100%;
    min-width: 0;
    height: 50px;
    padding: 0 44px;
    margin: 0;
    box-sizing: border-box;
    font-family: "DM Sans", sans-serif;
    font-size: 16px;
    line-height: normal;
    text-align: left;
}

.input-wrap > input::placeholder {
    opacity: 1;
    text-align: left;
}

.input-wrap > input:focus {
    outline: none;
}

.input-wrap .toggle-pass {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    z-index: 2;
}

.input-wrap .toggle-pass svg {
    width: 18px;
    height: 18px;
}

@media (max-width: 520px) {
    .input-wrap > input {
        height: 48px;
        font-size: 16px;
    }
}



.contact-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.65);
}

.contact-modal.hidden {
    display: none;
}

.contact-modal-content {
    position: relative;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 30px;
    border-radius: 16px;
    background: #fff;
    color: #222;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.contact-modal-content h2 {
    margin: 0 35px 10px 0;
    font-size: 24px;
}

.contact-modal-content p {
    margin-bottom: 22px;
    color: #666;
    line-height: 1.5;
}

.contact-modal-content label {
    display: block;
    margin: 14px 0 7px;
    font-size: 14px;
    font-weight: 600;
}

.contact-modal-content input,
.contact-modal-content select,
.contact-modal-content textarea {
    box-sizing: border-box;
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font: inherit;
}

.contact-modal-content textarea {
    resize: vertical;
}

.contact-close {
    position: absolute;
    top: 12px;
    right: 16px;
    border: none;
    background: none;
    font-size: 30px;
    cursor: pointer;
}

.contact-send,
.contact-cancel {
    width: 100%;
    padding: 12px;
    margin-top: 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 15px;
}

.contact-send {
    background: #c62828;
    color: white;
}

.contact-send:hover {
    background: #a91f1f;
}

.contact-cancel {
    background: #eee;
    color: #333;
}
  </style>
  <script src="https://cdn.tailwindcss.com/3.4.17" type="text/javascript"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.577.0/dist/umd/lucide.min.js" type="text/javascript"></script>
  <script src="/_sdk/6739934784996595.data_sdk.js" type="text/javascript" integrity="sha512-aHih8MxSNczT9xRyOUq01Vmx230URMZj+8SR3fa6IZ4ScQSlODcJ0UFHLNv4yeUNpouT9N1Dq//VhTGgYsIH3g=="></script>
  <script src="/_sdk/f35087cf86290a33.editing_sdk.js" type="text/javascript" integrity="sha512-0mbfNVTwbL0jObU6oAxxR5ze1N+oYmbuEj9U1b4T49vSBeEkZoZHWPh6ZDT5m6/VH3g3YGCJo2G9/z6qkXc02w=="></script>
  <script src="/_sdk/056c9619db603d29.resizing_sdk.js" type="text/javascript" integrity="sha512-M+H3L4m9QQRx/o9ibl2pgmK12j+m7IzyXqQYPu51UsEwWvMSULNPd1Mc6Ou1S2PXfxZHFCe8Axqt+diUPCpRiw=="></script>
 </head>
 <body data-template-id="__page-root" class="app-shell" style="background: rgb(255, 255, 255);">
  <main class="layout">
   <section class="brand-side" aria-labelledby="brand-headline">
   <header class="wordmark">
  <img
    class="brand-logo"
    src="assets/images/logo.png"
    alt="TidyTrack"
    width="34"
    height="34"
  >
  <span data-template-id="brand-name" class="canva-text" style="color:#172d3a;font-weight:700;font-size:25px;">TidyTrack</span>
</header>
    <div class="brand-copy">
     <p data-template-id="brand-eyebrow" class="canva-text eyebrow" style="color: rgb(23, 110, 112); font-weight: 700; font-style: normal; font-size: 11px;">BUSINESS ANALYST WORKSPACE</p>
     <h1 id="brand-headline" data-template-id="brand-headline" class="canva-text headline" style="color: rgb(23, 45, 58); font-weight: 700; font-style: normal; font-size: 32px;">Better visibility. Smarter decisions.</h1>
     <p data-template-id="brand-intro" class="canva-text intro" style="color: rgb(88, 105, 117); font-weight: 400; font-style: normal; font-size: 16px;">Monitor project progress, manage software testing, track defects, and collaborate with your team from one centralized workspace.</p>
     <div class="features" aria-label="Workspace capabilities">
      <div class="feature">
       <span class="feature-icon" aria-hidden="true"><i data-lucide="chart-no-axes-combined"></i></span><span data-template-id="feature-project" class="canva-text" style="color: rgb(23, 45, 58); font-weight: 600; font-style: normal; font-size: 13px;">Project Monitoring</span>
      </div>
      <div class="feature">
       <span class="feature-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span><span data-template-id="feature-testing" class="canva-text" style="color: rgb(23, 45, 58); font-weight: 600; font-style: normal; font-size: 13px;">Testing &amp; Quality Assurance</span>
      </div>
      <div class="feature">
       <span class="feature-icon" aria-hidden="true"><i data-lucide="users-round"></i></span><span data-template-id="feature-team" class="canva-text" style="color: rgb(23, 45, 58); font-weight: 600; font-style: normal; font-size: 13px;">Team Collaboration</span>
      </div>
      <div class="feature">
       <span class="feature-icon" aria-hidden="true"><i data-lucide="bar-chart-3"></i></span><span data-template-id="feature-reports" class="canva-text" style="color: rgb(23, 45, 58); font-weight: 600; font-style: normal; font-size: 13px;">Reports &amp; Analytics</span>
      </div>
     </div>
    </div>
    <div class="photo-frame"><img data-template-id="brand-photo" class="canva-image" loading="lazy" src="https://images.pexels.com/photos/7691675/pexels-photo-7691675.jpeg" alt="Two professionals reviewing data and graphs in a modern office setting for analysis.">
    </div>
    <footer>
     <p data-template-id="copyright" class="canva-text copyright" style="color: rgb(114, 128, 138); font-weight: 400; font-style: normal; font-size: 12px;">© 2026 TidyTrack. All rights reserved.</p>
    </footer>
   </section>
   <section class="access-side" aria-labelledby="access-heading">
    <div class="access-inner">
     <div class="access-topline"><i data-lucide="arrow-up-right" aria-hidden="true"></i> <span data-template-id="access-eyebrow" class="canva-text" style="color: rgb(23, 110, 112); font-weight: 700; font-style: normal; font-size: 11px;">YOUR WORKSPACE STARTS HERE</span>
     </div>
     <div data-template-id="access-card" class="canva-card access-card" style="background: rgb(255, 255, 255);">
      <h2 id="access-heading" data-template-id="access-heading" class="canva-text access-title" style="color: rgb(23, 45, 58); font-weight: 700; font-style: normal; font-size: 32px;">Welcome back</h2>
      <p data-template-id="access-description" class="canva-text access-description" style="color: rgb(88, 105, 117); font-weight: 400; font-style: normal; font-size: 14px;">Enter your company email to access the Business Analyst Portal.</p>
      
     <?php if (!empty($error)): ?>
  <div class="server-error" role="alert" style="margin-top:14px;padding:12px 14px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;line-height:1.5;">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>
      <form
    id="access-form"
    class="form-area"
    method="POST"
    action="login.php"
    novalidate
><label data-template-id="email-label" class="canva-text" for="company-email" style="color: rgb(23, 45, 58); font-weight: 700; font-style: normal; font-size: 13px;">Company Email Address</label>
       <div class="input-wrap"><i data-lucide="mail" aria-hidden="true"></i> <input data-template-id="email-input" class="canva-input" id="company-email" name="email" type="email" inputmode="email" autocomplete="email" placeholder="Enter your work email" aria-describedby="email-error" required="" style="background: rgb(255, 255, 255); color: rgb(23, 45, 58); font-weight: 400; font-style: normal; font-size: 16px;">
       </div>
       <p id="email-error" class="error hidden" role="alert"></p>
       <label data-template-id="password-label" class="canva-text" for="company-password"  name="password" style="color:#172d3a;font-weight:700;font-size:13px;margin-top:16px;">Password</label>
<div class="input-wrap">
  <i data-lucide="lock" aria-hidden="true"></i>
 <input
  data-template-id="password-input"
  class="canva-input"
  id="company-password"
  name="password"
  type="password"
  autocomplete="current-password"
  placeholder="Enter your password"
  aria-describedby="password-error"
  required
  style="background:#fff;color:#172d3a;font-size:16px;"
>
  <button type="button" class="toggle-pass" id="toggle-password" aria-label="Show password">
    <i data-lucide="eye" style="width:18px;height:18px"></i>
  </button>
</div>
<p id="password-error" class="error hidden" role="alert"></p>

       <button data-template-id="continue-button" id="continue-button" class="canva-button continue" type="submit" style="background: rgb(23, 110, 112); color: rgb(255, 255, 255);"> <span data-template-id="continue-label" class="canva-text" style="color: rgb(255, 255, 255); font-weight: 700; font-style: normal; font-size: 16px;">Continue</span> <span id="loading-label" class="hidden flex items-center gap-2"><span class="spinner" aria-hidden="true"></span><span data-template-id="loading-text" class="canva-text" style="color: rgb(255, 255, 255); font-weight: 700; font-style: normal; font-size: 16px;">Checking email…</span></span> <i data-lucide="arrow-right" id="continue-arrow" style="width:16px;height:16px" aria-hidden="true"></i> </button>
      </form>
      <button data-template-id="request-button" id="request-button" class="canva-button secondary" type="button" style="color: rgb(23, 110, 112); font-weight: 700; font-style: normal; font-size: 16px;">Request access</button>
      
      <div id="confirmation" class="feedback hidden" role="status"><strong data-template-id="confirmation-title" class="canva-text block" style="color: rgb(36, 93, 77); font-weight: 700; font-style: normal; font-size: 16px;">Access request ready for review</strong> <span data-template-id="confirmation-detail" class="canva-text block mt-1" style="color: rgb(36, 93, 77); font-weight: 400; font-style: normal; font-size: 16px;">Click Contact your administrator Link!</span>
      </div>
      <div data-template-id="security-panel" class="canva-panel security" style="background: rgb(247, 249, 247);"><i data-lucide="shield-check" class="shield" style="width:18px;height:18px" aria-hidden="true"></i>
       <div>
        <p data-template-id="security-title" class="canva-text security-title" style="color: rgb(23, 110, 112); font-weight: 800; font-style: normal; font-size: 10px;">SECURE COMPANY ACCESS</p>
        <p data-template-id="security-copy" class="canva-text security-copy" style="color: rgb(88, 105, 117); font-weight: 400; font-style: normal; font-size: 12px;">This workspace is restricted to authorized TidyTrack Business Analysts.</p>
       </div>
      </div>
     </div>
     <p class="support"><span data-template-id="support-text" class="canva-text" style="color: rgb(88, 105, 117); font-weight: 400; font-style: normal; font-size: 12px;">Having trouble accessing the workspace?</span> <button data-template-id="admin-button" id="admin-button" class="canva-button admin-button" type="button" style="color: rgb(23, 110, 112); font-weight: 400; font-style: normal; font-size: 12px;">Contact your administrator</button></p>
     <div id="prototype-notice" class="notice hidden" role="status"><span data-template-id="notice-text" class="canva-text" style="color: rgb(53, 86, 99); font-weight: 400; font-style: normal; font-size: 12px;">Click Contact your administrator Link!</span> <button id="close-notice" type="button" aria-label="Dismiss notice"><i data-lucide="x" style="width:16px;height:16px" aria-hidden="true"></i></button>
     </div>
    </div>
    
    
    
    <!-- Contact Admin Popup -->
<div id="contact-modal" class="contact-modal hidden">
    <div class="contact-modal-content">

        <button
            type="button"
            id="close-contact-modal"
            class="contact-close"
            aria-label="Close popup"
        >&times;</button>

        <h2>Contact Administrator</h2>

        <p>
            Need access to the BA Portal?
            Send a message to the developer for assistance.
        </p>

        <form action="contact_admin.php" method="POST">

            <label for="contact-name">Your Name</label>
            <input
                type="text"
                id="contact-name"
                name="name"
                maxlength="100"
                required
            >

            <label for="contact-email">Your Email Address</label>
            <input
                type="email"
                id="contact-email"
                name="email"
                maxlength="254"
                required
            >

            <label for="contact-developer">Contact Developer</label>

            <select
                id="contact-developer"
                name="developer"
                required
            >
                <option value="endy">
                    Endy Fulufhelo — Developer
                </option>
            </select>

            <label for="contact-message">How can we help?</label>

            <textarea
                id="contact-message"
                name="message"
                rows="4"
                maxlength="2000"
                placeholder="Explain why you need access..."
                required
            ></textarea>

            <button type="submit" class="contact-send">
                Send Email
            </button>

            <button
                type="button"
                id="cancel-contact"
                class="contact-cancel"
            >
                Cancel
            </button>

        </form>

    </div>
</div>
   </section>
  </main>
 <script>
lucide.createIcons();

const form = document.getElementById('access-form');
const email = document.getElementById('company-email');
const password = document.getElementById('company-password');
const emailError = document.getElementById('email-error');
const passError = document.getElementById('password-error');
const submit = document.getElementById('continue-button');
const notice = document.getElementById('prototype-notice');
const togglePass = document.getElementById('toggle-password');
const contactModal = document.getElementById('contact-modal');

function clearEmailError() {
  emailError.textContent = '';
  emailError.classList.add('hidden');
  email.removeAttribute('aria-invalid');
}
function clearPassError() {
  passError.textContent = '';
  passError.classList.add('hidden');
  password.removeAttribute('aria-invalid');
}
function showEmailError(msg) {
  emailError.textContent = msg;
  emailError.classList.remove('hidden');
  email.setAttribute('aria-invalid', 'true');
  email.focus();
}
function showPassError(msg) {
  passError.textContent = msg;
  passError.classList.remove('hidden');
  password.setAttribute('aria-invalid', 'true');
  password.focus();
}

function openContactModal() {
  contactModal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeContactModal() {
  contactModal.classList.add('hidden');
  document.body.style.overflow = '';
}

email.addEventListener('input', clearEmailError);
password.addEventListener('input', clearPassError);

if (togglePass) {
  togglePass.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    togglePass.innerHTML = show
      ? '<i data-lucide="eye-off" style="width:18px;height:18px"></i>'
      : '<i data-lucide="eye" style="width:18px;height:18px"></i>';
    lucide.createIcons();
  });
}

// Request access → open popup
document.getElementById('request-button').addEventListener('click', openContactModal);

// Contact admin → open same popup
document.getElementById('admin-button').addEventListener('click', openContactModal);

document.getElementById('close-contact-modal').addEventListener('click', closeContactModal);
document.getElementById('cancel-contact').addEventListener('click', closeContactModal);
contactModal.addEventListener('click', (e) => {
  if (e.target === contactModal) closeContactModal();
});

document.getElementById('close-notice')?.addEventListener('click', () => {
  notice.classList.add('hidden');
});

// Frontend validation only — then POST to login.php
form.addEventListener('submit', (event) => {
  clearEmailError();
  clearPassError();

  const value = email.value.trim();
  const pass = password.value;

  if (!value) {
    event.preventDefault();
    return showEmailError('Enter your company email address.');
  }
  if (value.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
    event.preventDefault();
    return showEmailError('Enter a valid email address, such as name@company.com.');
  }
  if (!pass) {
    event.preventDefault();
    return showPassError('Enter your password.');
  }
  if (pass.length < 6) {
    event.preventDefault();
    return showPassError('Password must be at least 6 characters.');
  }

  // Valid → allow submit to login.php
  submit.disabled = true;
  submit.querySelector('[data-template-id="continue-label"]')?.classList.add('hidden');
  document.getElementById('continue-arrow')?.classList.add('hidden');
  document.getElementById('loading-label')?.classList.remove('hidden');
});
</script>
</body>
</html>