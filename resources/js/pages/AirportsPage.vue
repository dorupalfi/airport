<script setup>
import { onMounted, ref } from 'vue';
import NewAirportModal from '../components/NewAirportModal.vue';

const airports = ref([]);
const error = ref('');
const isLoading = ref(true);
const isCreateModalOpen = ref(false);

async function loadAirports() {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/airports', {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to load airports.');
        }

        airports.value = await response.json();
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadAirports);
</script>

<template>
    <section class="page" aria-labelledby="airports-title">
        <header class="page-header">
            <div>
                <p class="eyebrow">Operations</p>
                <h1 id="airports-title">Airports</h1>
                <p class="page-description">Airports currently configured for gate scheduling.</p>
            </div>
            <div class="page-actions">
                <span class="count-badge">
                    {{ isLoading ? 'Loading...' : `${airports.length} ${airports.length === 1 ? 'airport' : 'airports'}` }}
                </span>
                <button class="button" type="button" @click="isCreateModalOpen = true">New airport</button>
            </div>
        </header>

        <div class="data-card">
            <div v-if="isLoading" class="empty-state">Loading airports...</div>

            <div v-else-if="error" class="error-state">
                <p>{{ error }}</p>
                <button type="button" class="retry-button" @click="loadAirports">Try again</button>
            </div>

            <div v-else-if="airports.length === 0" class="empty-state">
                No airports have been configured yet.
            </div>

            <div v-else class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Airport</th>
                            <th scope="col">Code</th>
                            <th scope="col">Location</th>
                            <th scope="col">Default gate occupancy</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="airport in airports" :key="airport.id">
                            <td class="airport-name">{{ airport.name }}</td>
                            <td><span class="airport-code">{{ airport.code }}</span></td>
                            <td>{{ airport.city }}, {{ airport.country }}</td>
                            <td>{{ airport.default_gate_occupancy_minutes }} minutes</td>
                            <td class="table-action">
                                <a class="text-button" :href="`/airports/${airport.id}`">Edit</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <NewAirportModal
            v-if="isCreateModalOpen"
            @close="isCreateModalOpen = false"
            @created="isCreateModalOpen = false; loadAirports()"
        />
    </section>
</template>
