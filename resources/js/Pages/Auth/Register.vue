<script setup>
import { useForm, Link } from '@inertiajs/vue3';

let form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

let submit = () => {
    form.post('/register');
};
</script>

<template>
    <div class="mx-auto max-w-md p-8">
        <h1 class="mb-6 text-2xl font-semibold">Register</h1>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="w-full rounded border px-3"
                    required
                    autofocus
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="w-full rounded border px-3"
                    required
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="w-full rounded border px-3"
                    required
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="password_confirmation">Confirm password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="w-full rounded border px-3"
                    required
                />
            </div>

            <button
                type="submit"
                class="btn rounded bg-brand hover:bg-brand-hover px-4 text-white disabled:opacity-50"
                :disabled="form.processing"
            >
                Register
            </button>
        </form>

        <p class="mt-4 text-sm">
            Already registered?
            <Link href="/login" class="underline">Log in</Link>
        </p>
    </div>
</template>
