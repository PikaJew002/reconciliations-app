<script setup>
    import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue';
    import { Link, useForm, usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineOptions({ layout: AuthenticatedLayout });

    let props = defineProps({
        account: {
            type: Object,
            required: true,
        },
        institutions: {
            type: Array,
            required: true,
        },
        accountTypes: {
            type: Array,
            required: true,
        },
        defaultClassifications: {
            type: Array,
            required: true,
        },
    });

    let page = usePage();
    let flashSuccess = computed(() => page.props.flash?.success);

    let form = useForm({
        name: props.account.name ?? '',
        institution_name: props.account.institution_name ?? '',
        account_name: props.account.account_name ?? '',
        account_type: props.account.account_type ?? '',
        default_classification:
            props.account.default_classification ?? 'expense',
        currency: props.account.currency ?? 'USD',
        last_four: props.account.last_four ?? '',
    });

    let accountTypeLabel = (type) => {
        return type.replaceAll('_', ' ');
    };

    let classificationLabel = (classification) => {
        return classification === 'bill' ? 'Bill' : 'Expense';
    };

    let submit = () => {
        form.put(`/accounts/${props.account.id}`);
    };
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/accounts" class="text-sm underline">Back to accounts</Link>
            <h1 class="mt-2 text-2xl font-semibold">Edit account</h1>
            <p class="text-sm text-neutral-600">
                Update account details and the default type used when
                categorizing transactions.
            </p>
        </div>

        <p
            v-if="flashSuccess"
            class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
        >
            {{ flashSuccess }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="w-full rounded border px-3"
                    required
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="institution_name"
                    >Institution</label
                >
                <select
                    id="institution_name"
                    v-model="form.institution_name"
                    class="w-full rounded border px-3"
                    required
                >
                    <option disabled value="">Select an institution</option>
                    <option
                        v-for="institution in institutions"
                        :key="institution"
                        :value="institution"
                    >
                        {{ institution }}
                    </option>
                </select>
                <p
                    v-if="form.errors.institution_name"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.institution_name }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="account_type"
                    >Account type</label
                >
                <select
                    id="account_type"
                    v-model="form.account_type"
                    class="w-full rounded border px-3"
                    required
                >
                    <option disabled value="">Select a type</option>
                    <option
                        v-for="type in accountTypes"
                        :key="type"
                        :value="type"
                    >
                        {{ accountTypeLabel(type) }}
                    </option>
                </select>
                <p
                    v-if="form.errors.account_type"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.account_type }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="default_classification"
                    >Default transaction type</label
                >
                <select
                    id="default_classification"
                    v-model="form.default_classification"
                    class="w-full rounded border px-3"
                    required
                >
                    <option
                        v-for="classification in defaultClassifications"
                        :key="classification"
                        :value="classification"
                    >
                        {{ classificationLabel(classification) }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-neutral-500">
                    Used as the starting type when categorizing unmatched
                    transactions from this account.
                </p>
                <p
                    v-if="form.errors.default_classification"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.default_classification }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm" for="account_name"
                    >Account name
                    <span class="text-neutral-500">(optional)</span></label
                >
                <input
                    id="account_name"
                    v-model="form.account_name"
                    type="text"
                    class="w-full rounded border px-3"
                />
                <p
                    v-if="form.errors.account_name"
                    class="mt-1 text-sm text-red-600"
                >
                    {{ form.errors.account_name }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm" for="currency"
                        >Currency</label
                    >
                    <input
                        id="currency"
                        v-model="form.currency"
                        type="text"
                        maxlength="3"
                        class="w-full rounded border px-3 uppercase"
                        required
                    />
                    <p
                        v-if="form.errors.currency"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.currency }}
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-sm" for="last_four"
                        >Last four
                        <span class="text-neutral-500">(optional)</span></label
                    >
                    <input
                        id="last_four"
                        v-model="form.last_four"
                        type="text"
                        inputmode="numeric"
                        maxlength="4"
                        pattern="[0-9]{4}"
                        class="w-full rounded border px-3"
                    />
                    <p
                        v-if="form.errors.last_four"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.last_four }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="btn rounded bg-brand hover:bg-brand-hover px-4 text-white disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Save changes
                </button>
                <Link
                    href="/accounts"
                    class="btn rounded border px-4 text-neutral-700 hover:bg-neutral-100"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
