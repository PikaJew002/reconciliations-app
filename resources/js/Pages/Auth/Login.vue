<script setup>
import { useForm, Link } from '@inertiajs/vue3';

let form = useForm({
    email: '',
    password: '',
    remember: false,
});

let submit = () => {
    form.post('/login');
};
</script>

<template>
    <div class="mx-auto max-w-md p-8">
        <h1 class="mb-6 text-2xl font-semibold">Log in</h1>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="w-full rounded border px-3 py-2"
                    required
                    autofocus
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="w-full rounded border px-3 py-2"
                    required
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.remember" type="checkbox" />
                Remember me
            </label>

            <button
                type="submit"
                class="rounded bg-brand hover:bg-brand-hover px-4 py-2 text-white disabled:opacity-50"
                :disabled="form.processing"
            >
                Log in
            </button>
        </form>

        <p class="mt-4 text-sm">
            Need an account?
            <Link href="/register" class="underline">Register</Link>
        </p>
    </div>
</template>
