<script setup>
import { reactive, ref } from 'vue';

const emit = defineEmits(['close', 'created']);

const form = reactive({
    name: '',
    code: '',
    city: '',
    country: '',
    default_gate_occupancy_minutes: 90,
    gate_count: 0,
});
const error = ref('');
const isSaving = ref(false);

async function submit() {
    isSaving.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/airports', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(form),
        });

        if (!response.ok) {
            const payload = await response.json();
            throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to create the airport.');
        }

        emit('created');
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="new-airport-title">
            <header class="modal-header">
                <div>
                    <p class="eyebrow">Configuration</p>
                    <h2 id="new-airport-title">New airport</h2>
                </div>
                <button class="icon-button" type="button" aria-label="Close" @click="emit('close')">×</button>
            </header>

            <form @submit.prevent="submit">
                <div class="form-grid">
                    <label class="form-field form-field--wide">
                        Airport name
                        <input v-model.trim="form.name" required maxlength="255" placeholder="Frankfurt Airport">
                    </label>
                    <label class="form-field">
                        Code
                        <input v-model.trim="form.code" required maxlength="10" placeholder="EDDF">
                    </label>
                    <label class="form-field">
                        City
                        <input v-model.trim="form.city" required maxlength="100" placeholder="Frankfurt">
                    </label>
                    <label class="form-field">
                        Country
                        <input v-model.trim="form.country" required maxlength="100" placeholder="Germany">
                    </label>
                    <label class="form-field">
                        Default occupancy (minutes)
                        <input v-model.number="form.default_gate_occupancy_minutes" required type="number" min="1" max="1440">
                    </label>
                    <label class="form-field">
                        Number of gates
                        <input v-model.number="form.gate_count" required type="number" min="0" max="200">
                    </label>
                </div>

                <p v-if="error" class="form-error">{{ error }}</p>

                <footer class="modal-actions">
                    <button class="button button--secondary" type="button" @click="emit('close')">Cancel</button>
                    <button class="button" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Creating...' : 'Create airport' }}
                    </button>
                </footer>
            </form>
        </section>
    </div>
</template>
