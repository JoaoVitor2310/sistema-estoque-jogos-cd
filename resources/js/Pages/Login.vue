<script lang="ts">

</script>

<template>
    <div class="w-100 d-flex flex-row justify-content-center align-items-center">
        <div class="row col-12 col-md-6 col-lg-4 card text-center shadow p-3">
            <!-- <div class="aaa"> -->
            <h1>Login</h1>
            <a :href="route('auth.google.redirect')" class="text-decoration-none text-dark pt-2">
            <div class="d-flex flex-row justify-content-center align-items-center card gap-2 p-2 w-75 m-auto">
                <i class="pi pi-google"></i>
                    Login com o Google
                </div>
            </a>
            <!-- Login Section -->
            <div class="col-12 d-flex flex-column align-items-center justify-content-center gap-3 py-4">
                <div class="d-flex flex-column gap-2">
                    <label for="username">Username</label>
                    <input id="username" type="text" class="form-control" />
                </div>
                <div class="d-flex flex-column gap-2">
                    <label for="password">Password</label>
                    <input id="password" type="password" class="form-control" />
                </div>
                <div class="d-flex">
                    <button class="btn btn-primary w-100 mx-auto" disabled style="max-width: 17.35rem;">
                        <i class="pi pi-user"></i> Login
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>