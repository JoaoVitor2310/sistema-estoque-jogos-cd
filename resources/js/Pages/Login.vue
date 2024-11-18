<script setup lang="ts">
import Button from 'primevue/button';
import { reactive, ref } from 'vue';
import { InputText } from 'primevue';
import Password from 'primevue/password';
import { showResponse } from '@/helpers/showResponse';
import axiosInstance from '@/axios';
import Toast from 'primevue/toast';
import { useToast } from "primevue/usetoast";

const toast = useToast();

const handleRequest = async (endpoint: string, data: object) => {
    try {
        const res = await axiosInstance.post(endpoint, data);
        showResponse(res, toast.add);
        if (res.status === 200) {
            window.location.href = route('venda-chave-troca');
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Erro Interno, tente novamente.',
            detail: error,
            life: 7000
        });
        console.log(error);
    }
};

const onLogin = async () => {
    await handleRequest('/login', {
        email: formData.email,
        password: formData.password,
    });
};

const onRegister = async () => {
    if (!isRegister.value) {
        isRegister.value = true;
        return;
    }
    
    await handleRequest('/register', {
        email: formData.email,
        name: formData.name,
        password: formData.password,
        password_confirmation: formData.confirmPassword,
    });
};

const onForgotPassword = async () => {
    // console.log('a')
    await handleRequest('/forgot-password', {
        email: formData.email,
    });
};

const isRegister = ref(false);

const formData = reactive({
    email: '',
    name: '',
    password: '',
    confirmPassword: '',
})

</script>

<template>
    <Toast position="bottom-right" />
    <div class="w-100 d-flex flex-row justify-content-center align-items-center">
        <div class="row col-12 col-md-6 col-lg-4 card text-center shadow p-3">
            <h1>Login</h1>
            <a :href="route('auth.google.redirect')" class="text-decoration-none text-dark pt-2">
                <div class="d-flex flex-row justify-content-center align-items-center card gap-2 p-2 w-75 m-auto">
                    <i class="pi pi-google"></i>
                    Login com o Google
                </div>
            </a>
            <div class="col-12 d-flex flex-column align-items-center justify-content-center gap-3 py-4">
                <div class="d-flex flex-column gap-2">
                    <label for="username">Email</label>
                    <InputText v-model="formData.email" />
                </div>
                <div v-if="isRegister" class="d-flex flex-column gap-2">
                    <label for="username">Nome</label>
                    <InputText v-model="formData.name" />
                </div>
                <div class="d-flex flex-column gap-2">
                    <label for="password">Senha</label>
                    <Password v-model="formData.password" toggleMask :feedback="false" />
                </div>
                <div v-if="isRegister" class="d-flex flex-column gap-2">
                    <label for="password">Confirme a Senha</label>
                    <Password v-model="formData.confirmPassword" toggleMask :feedback="false" />
                </div>
                <div class="d-flex flex-row gap-2 w-75">
                    <Button v-if="!isRegister" @click="onLogin" class="btn btn-primary w-100 mx-auto" style="max-width: 17.35rem;">
                        <i class="pi pi-user"></i> Login
                    </button>
                    <Button @click="onRegister" severity="secondary" class="btn w-100 mx-auto"
                        style="max-width: 17.35rem;">
                        <i class="pi pi-user-plus"></i> Cadastrar-se
                    </button>
                    <!-- <Button @click="onForgotPassword" severity="secondary" class="btn w-100 mx-auto"
                        style="max-width: 17.35rem;">
                        <i class="pi pi-lock"></i> Esqueci a senha
                    </button> -->
                </div>
            </div>
        </div>
    </div>
</template>