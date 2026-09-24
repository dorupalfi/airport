<script setup>
import { reactive, ref } from 'vue';

const props = defineProps({
    gate: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close', 'saved']);
const error = ref('');
const isSaving = ref(false);

const form = reactive({
    code: props.gate.code,
    occupancy_minutes: props.gate.occupancy_minutes,
    is_active: props.gate.is_active,
    exceptions: (props.gate.exceptions ?? []).map((exception) => ({
        id: exception.id,
        start_date: exception.start_date?.slice(0, 10) ?? '',
        end_date: exception.end_date?.slice(0, 10) ?? '',
        reason: exception.reason ?? '',
    })),
});

function addException() {
    form.exceptions.push({ id: null, start_date: '', end_date: '', reason: '' });
}

function removeException(index) {
    form.exceptions.splice(index, 1);
}

async function saveGate() {
    isSaving.value = true;
    error.value = '';

    try {
        const response = await fetch(`/api/gates/${props.gate.id}`, {
            method: 'PUT',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(form),
        });

        if (!response.ok) {
            const payload = await response.json();
            throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to save gate.');
        }

        emit('saved', await response.json());
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" role="presentation" @click.self="emit('close')">
        <section class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="edit-gate-title">
            <header class="modal-header">
                <div>
                    <p class="eyebrow">Gate configuration</p>
                    <h2 id="edit-gate-title">Edit gate {{ gate.code }}</h2>
                </div>
                <button class="icon-button" type="button" aria-label="Close" @click="emit('close')">&times;</button>
            </header>

            <form @submit.prevent="saveGate">
                <div class="form-grid">
                    <label class="form-field">
                        Gate code
                        <input v-model.trim="form.code" required maxlength="10">
                    </label>
                    <label class="form-field">
                        Occupancy (minutes)
                        <input v-model.number="form.occupancy_minutes" required type="number" min="1" max="1440">
                    </label>
                    <label class="form-field form-field--wide">
                        Status
                        <select v-model="form.is_active">
                            <option :value="true">Active</option>
                            <option :value="false">Inactive</option>
                        </select>
                    </label>
                </div>

                <section class="exceptions-section" aria-labelledby="exceptions-title">
                    <div class="exceptions-heading">
                        <div>
                            <h3 id="exceptions-title">Exceptions</h3>
                            <p>Set dates when this gate has a different availability or operational restriction.</p>
                        </div>
                        <button class="button button--secondary" type="button" @click="addException">Add exception</button>
                    </div>

                    <p v-if="form.exceptions.length === 0" class="exceptions-empty">No exceptions configured.</p>

                    <div v-else class="exception-list">
                        <div v-for="(exception, index) in form.exceptions" :key="exception.id ?? `new-${index}`" class="exception-row">
                            <label class="form-field">
                                Start date
                                <input v-model="exception.start_date" required type="date">
                            </label>
                            <label class="form-field">
                                End date
                                <input v-model="exception.end_date" required type="date">
                            </label>
                            <label class="form-field">
                                Reason
                                <input v-model.trim="exception.reason" required maxlength="255" placeholder="e.g. Maintenance">
                            </label>
                            <button class="button button--danger" type="button" @click="removeException(index)">Remove</button>
                        </div>
                    </div>
                </section>

                <p v-if="error" class="form-error">{{ error }}</p>

                <div class="modal-actions">
                    <button class="button button--secondary" type="button" :disabled="isSaving" @click="emit('close')">Cancel</button>
                    <button class="button" type="submit" :disabled="isSaving">
                        {{ isSaving ? 'Saving...' : 'Save gate' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
