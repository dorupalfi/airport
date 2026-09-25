<script setup>
import { onMounted, ref } from 'vue';
import GateEditModal from '../components/GateEditModal.vue';

const props = defineProps({
    airportId: {
        type: Number,
        required: true,
    },
});

const airport = ref(null);
const error = ref('');
const form = ref({});
const isLoading = ref(true);
const isSaving = ref(false);
const selectedGate = ref(null);
const successMessage = ref('');

function updateForm(data) {
    form.value = {
        name: data.name,
        code: data.code,
        city: data.city,
        country: data.country,
        default_gate_occupancy_minutes: data.default_gate_occupancy_minutes,
        gate_count: data.gates?.length ?? 0,
    };
}

async function loadAirport() {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await fetch(`/api/airports/${props.airportId}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(response.status === 404 ? 'Airport not found.' : 'Unable to load airport.');
        }

        airport.value = await response.json();
        updateForm(airport.value);
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoading.value = false;
    }
}

async function saveAirport() {
    isSaving.value = true;
    error.value = '';
    successMessage.value = '';

    try {
        const response = await fetch(`/api/airports/${props.airportId}`, {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(form.value),
        });

        if (!response.ok) {
            const payload = await response.json();
            throw new Error(Object.values(payload.errors ?? {}).flat()[0] ?? 'Unable to save airport.');
        }

        airport.value = await response.json();
        updateForm(airport.value);
        successMessage.value = airport.value.reallocation_queued
            ? 'Airport saved. Flight allocation restarted.'
            : 'Airport saved.';
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isSaving.value = false;
    }
}

function formatDate(value) {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', hour12: false }).format(new Date(value));
}

function saveGate(updatedGate) {
    const index = airport.value.gates.findIndex((gate) => gate.id === updatedGate.id);

    if (index !== -1) {
        airport.value.gates.splice(index, 1, updatedGate);
    }

    successMessage.value = updatedGate.reallocation_queued
        ? 'Gate saved. Flight allocation restarted.'
        : 'Gate saved.';
    selectedGate.value = null;
}

onMounted(loadAirport);
</script>

<template>
    <section class="page" aria-labelledby="airport-title">
        <a class="back-link" href="/airports">← Back to airports</a>

        <div v-if="isLoading" class="data-card empty-state">Loading airport...</div>

        <div v-else-if="error && !airport" class="data-card error-state">
            <p>{{ error }}</p>
            <a class="text-button" href="/airports">Return to airports</a>
        </div>

        <template v-else>
            <header class="page-header page-header--edit">
                <div>
                    <p class="eyebrow">Airport configuration</p>
                    <h1 id="airport-title">{{ airport.name }}</h1>
                    <p class="page-description">Edit airport details and review its configured gates.</p>
                </div>
                <span class="count-badge">{{ airport.gates.length }} {{ airport.gates.length === 1 ? 'gate' : 'gates' }}</span>
            </header>

            <div class="edit-layout">
                <section class="data-card edit-card" aria-labelledby="airport-details-title">
                    <div class="card-title-row">
                        <h2 id="airport-details-title">Airport details</h2>
                    </div>

                    <form class="edit-form" @submit.prevent="saveAirport">
                        <div class="form-grid">
                            <label class="form-field form-field--wide">
                                Airport name
                                <input v-model.trim="form.name" required maxlength="255">
                            </label>
                            <label class="form-field">
                                Code
                                <input v-model.trim="form.code" required maxlength="10">
                            </label>
                            <label class="form-field">
                                City
                                <input v-model.trim="form.city" required maxlength="100">
                            </label>
                            <label class="form-field">
                                Country
                                <input v-model.trim="form.country" required maxlength="100">
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
                        <p v-if="successMessage" class="form-success">{{ successMessage }}</p>

                        <div class="form-actions">
                            <button class="button" type="submit" :disabled="isSaving">
                                {{ isSaving ? 'Saving...' : 'Save airport' }}
                            </button>
                        </div>
                    </form>
                </section>

                <section class="data-card gates-card" aria-labelledby="gates-title">
                    <div class="card-title-row">
                        <h2 id="gates-title">Gates</h2>
                        <span>{{ airport.gates.length }} total</span>
                    </div>

                    <div v-if="airport.gates.length === 0" class="empty-state">No gates configured.</div>
                    <div v-else class="table-scroll">
                        <table class="gates-table">
                            <thead>
                                <tr>
                                    <th scope="col">Code</th>
                                    <th scope="col">Occupancy</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Exceptions</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Last updated</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="gate in airport.gates" :key="gate.id">
                                    <td><span class="airport-code">{{ gate.code }}</span></td>
                                    <td>{{ gate.occupancy_minutes }} minutes</td>
                                    <td>
                                        <span class="status-pill" :class="{ 'status-pill--inactive': !gate.is_active }">
                                            {{ gate.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>{{ gate.exceptions?.length ?? 0 }}</td>
                                    <td>{{ formatDate(gate.created_at) }}</td>
                                    <td>{{ formatDate(gate.updated_at) }}</td>
                                    <td class="table-action">
                                        <button class="text-button" @click="selectedGate = gate">Edit gate</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <GateEditModal
                v-if="selectedGate"
                :gate="selectedGate"
                @close="selectedGate = null"
                @saved="saveGate"
            />
        </template>
    </section>
</template>
<style scoped>
button.text-button {
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}
</style>
