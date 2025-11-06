@extends('layouts.auth')

@section('content')

@if (session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="login">
    <div class="container " id="container">
        <div class="form-container sign-up-container">
            <form method="POST" action="{{ route('auth.register.store') }}">
                @csrf
                <h1>Create Account</h1>
                <div class="social-container">
                    <a href="#" class="social"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="social"><i class="fab fa-google-plus-g"></i></a>
                    <a href="#" class="social"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <span>or use your email for registration</span>
                <div class="w-100">
                    <input type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" placeholder="Name" />
                    @error('name')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="w-100">
                    <input type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" placeholder="Email" />
                    @error('email')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="w-100">
                    <input type="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="Password" />
                    @error('password')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <button class="mt-4">Sign Up</button>
            </form>
        </div>
        <div class="form-container sign-in-container">
            <form method="POST" action="{{ route('auth.login') }}">
                @csrf
                <h1 class="fw-bold mb-2">Sign in</h1>
                <span>or use your account</span>
                <div class="w-100">
                    <input type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" placeholder="Email" />
                    @error('email')
                        <span class="invalid-feedback mb-3" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="w-100">
                    <input type="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="Password" />
                    @error('password')
                        <span class="invalid-feedback mb-3" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <button type="submit" class="mt-4">
                    {{ __('Login') }}
                </button>

                @if (Route::has('password.request'))
                    <a class="btn btn-link" href="{{ route('password.request') }}">
                        {{ __('Forgot Your Password?') }}
                    </a>
                @endif
                <p class="position-absolute bottom-0 start-0 m-2 ps-3 text-muted" style="font-size: 10px;">
                    Powered By Novulutions Inc | <a href="#" class="text-decoration-none" style="font-size: 10px;" data-bs-toggle="modal" data-bs-target="#privacyModal">Data Privacy Policy</a>
                </p>
            </form>
        </div>
        <div class="overlay-container">
            <div class="overlay">
                <div class="overlay-panel overlay-left">
                    <h1>Welcome Back!</h1>
                    <p>To keep connected with us please login with your personal info</p>
                    <button class="ghost" id="signIn">Sign In</button>
                </div>
                <div class="overlay-panel overlay-right">
                    <img src="{{ asset(config('app.product') === 'novustream' ? 'images/clientnobgb.png' : 'images/novusurgelogo.png') }}" alt="" class="w-75">
                    <p>Are you ready to view your bills? and proceed to payments? Start now by creating an account!</p>
                    <a href="{{ route('register')  }}" class="btn btn-primary fw-bold text-white border-2 fs-6 px-5 py-3 text-uppercase fw-bold" id="signUp">Sign Up</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="privacyModalLabel">Data Privacy Policy</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="font-size: 0.95rem; line-height: 1.6;">
        <p class="text-muted"><strong>Last updated:</strong> November 3, 2025</p>
        <p>The Novulutions Inc value your privacy. This Privacy Policy explains how we collect, use, and protect your personal information when you use our Service.</p>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">1. Information We Collect</h6>
          <p><strong>Personal Information:</strong> Name, email, phone, account info, payment info.</p>
          <p><strong>Non-Personal Information:</strong> Browser, device, IP, pages visited, cookies.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">2. How We Use Your Information</h6>
          <ul class="mb-0 ps-3">
            <li>Provide and maintain our Service</li>
            <li>Process transactions and send confirmations</li>
            <li>Respond to comments, questions, or requests</li>
            <li>Improve our website, products, and services</li>
            <li>Send updates, promotional materials, or important notices</li>
            <li>Ensure security and prevent fraudulent activities</li>
          </ul>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">3. How We Share Your Information</h6>
          <p>We do not sell your information. We share it only with service providers or legal authorities if required.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">4. Cookies</h6>
          <p>Cookies are used to remember preferences, analyze usage, and improve user experience. You can disable cookies in your browser, but some functionality may be affected.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">5. Data Retention</h6>
          <p>We retain personal information only as long as necessary to fulfill purposes, comply with legal obligations, or resolve disputes.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">6. Security</h6>
          <p>We implement reasonable measures to protect your data, but no online system is 100% secure.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">7. Your Rights</h6>
          <p>You can access, update, delete, or opt out of communications by contacting us at <a href="mailto:contact@novulutions.com">contact@novulutions.com</a>.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">8. Links to Other Sites</h6>
          <p>We are not responsible for third-party websites. Please review their privacy policies separately.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">9. Changes to This Privacy Policy</h6>
          <p>We may update this Policy. Updates will be posted with a new “Last updated” date.</p>
        </div>

        <div class="border-top my-3 pt-3">
          <h6 class="fw-bold text-primary">10. Contact Us</h6>
          <p>If you have questions, reach us at <a href="mailto:contact@novulutions.com">contact@novulutions.com</a>.</p>
          <p class="fs-6">Address: 35th Floor, Eco Tower, Bonifacio Global City, 9th Ave. Corner 32nd St. Bonifacio Global City</p>
          <p class="fs-6">Email: <a href="mailto:dpo@novulutions.com">dpo@novulutions.com</a> </p>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
    @media(min-width: 0px) and (max-width: 600px) {
        .overlay-container {
            display: none;
        }

        .login {
            width: 90%;
            display: flex;
            margin: auto !important;
            justify-content: center;
        }

        .login .sign-in-container {
            width: 100%;
        }

        .login form {
            padding: 20px;
        }
    }
</style>
@endsection


