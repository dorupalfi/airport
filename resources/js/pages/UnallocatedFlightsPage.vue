<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import PaginationControls from '../components/PaginationControls.vue';

const airports = ref([]);
const dates = ref([]);
const flights = ref([]);
const pagination = ref({ currentPage: 1, lastPage: 1, total: 0 });
const selectedAirportId = ref('');
const selectedDate = ref('');
const search = ref('');
const error = ref('');
const isLoadingFilters = ref(true);
const isLoadingFlights = ref(false);
const filtersReady = ref(false);

const selectedAirport = computed(() => airports.value.find((airport) => airport.id === Number(selectedAirportId.value)));

async function loadFilters() {
    isLoadingFilters.value = true;
    error.value = '';

    try {
        const response = await fetch('/api/unallocated-flights/filters', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load unallocated-flight filters.');
        }

        const payload = await response.json();
        airports.value = payload.airports;
        dates.value = payload.dates;
        selectedAirportId.value = airports.value[0]?.id ?? '';
        selectedDate.value = dates.value[0] ?? '';
        filtersReady.value = true;
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoadingFilters.value = false;
    }
}

async function loadFlights(page = 1) {
    if (!filtersReady.value || !selectedAirportId.value || !selectedDate.value) {
        flights.value = [];
        pagination.value = { currentPage: 1, lastPage: 1, total: 0 };

        return;
    }

    isLoadingFlights.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams({
            airport_id: selectedAirportId.value,
            date: selectedDate.value,
            page,
        });

        if (search.value) {
            query.set('search', search.value);
        }

        const response = await fetch(`/api/unallocated-flights?${query}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load unallocated flights.');
        }

        const payload = await response.json();
        flights.value = payload.data;
        pagination.value = {
            currentPage: payload.current_page,
            lastPage: payload.last_page,
            total: payload.total,
        };
    } catch (requestError) {
        error.value = requestError.message;
    } finally {
        isLoadingFlights.value = false;
    }
}

async function reloadPage() {
    await loadFilters();
    await loadFlights();
}

function formatDateTime(value) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        hour12: false,
        timeZone: 'UTC',
    }).format(new Date(value));
}

function goToPage(page) {
    if (page >= 1 && page <= pagination.value.lastPage) {
        loadFlights(page);
    }
}

watch([selectedAirportId, selectedDate], loadFlights);
onMounted(async () => {
    await loadFilters();
    await loadFlights();
});
</script>

<template>
    <section class="page" aria-labelledby="unallocated-flights-title">
        <header class="page-header">
            <div>
                <p class="eyebrow">Operations</p>
                <h1 id="unallocated-flights-title">Unallocated flights</h1>
                <p class="page-description">Flights that could not be assigned to a gate. All times are UTC.</p>
            </div>
            <span class="count-badge">{{ pagination.total }} {{ pagination.total === 1 ? 'flight' : 'flights' }}</span>
        </header>

        <section class="data-card schedule-filters" aria-label="Unallocated-flight filters">
            <div class="filter-grid">
                <label class="form-field">
                    Airport
                    <select v-model="selectedAirportId" :disabled="isLoadingFilters || airports.length === 0">
                        <option v-for="airport in airports" :key="airport.id" :value="airport.id">
                            {{ airport.code }} — {{ airport.name }}
                        </option>
                    </select>
                </label>
                <label class="form-field">
                    Planned departure date
                    <select v-model="selectedDate" :disabled="isLoadingFilters || dates.length === 0">
                        <option v-for="date in dates" :key="date" :value="date">{{ date }}</option>
                    </select>
                </label>
                <form class="schedule-search" @submit.prevent="loadFlights">
                    <label class="form-field" for="unallocated-flight-search">
                        Search destination airport or city
                        <input id="unallocated-flight-search" v-model.trim="search" type="search" placeholder="e.g. London, EGLL">
                    </label>
                    <button class="button button--secondary" type="submit" :disabled="isLoadingFlights">Search</button>
                </form>
            </div>
        </section>

        <div class="data-card schedule-card">
            <div v-if="isLoadingFilters || isLoadingFlights" class="empty-state">Loading unallocated flights...</div>

            <div v-else-if="error" class="error-state">
                <p>{{ error }}</p>
                <button class="retry-button" type="button" @click="reloadPage">Try again</button>
            </div>

            <div v-else-if="airports.length === 0" class="empty-state">No managed airports are configured.</div>

            <div v-else-if="dates.length === 0" class="empty-state">No unallocated flights are available yet.</div>

            <div v-else-if="flights.length === 0" class="empty-state">
                No unallocated flights for {{ selectedAirport?.code }} on {{ selectedDate }}.
            </div>

            <div v-else class="table-scroll">
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th scope="col">Destination</th>
                            <th scope="col">Callsign</th>
                            <th scope="col">Planned departure</th>
                            <th scope="col">Unallocation reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="flight in flights" :key="flight.id">
                            <td>
                                <template v-if="flight.destination">
                                    <span class="destination-name">{{ flight.destination.name }}</span>
                                    <span class="destination-location">{{ flight.destination.code }} · {{ flight.destination.city }}, {{ flight.destination.country }}</span>
                                </template>
                                <span v-else class="destination-location">
                                    {{ flight.arrival_external_airport_code ? `Destination unavailable (${flight.arrival_external_airport_code})` : 'Destination unavailable' }}
                                </span>
                            </td>
                            <td>{{ flight.callsign || '—' }}</td>
                            <td>{{ formatDateTime(flight.planned_departure_at) }}</td>
                            <td><span class="destination-location">{{ flight.unallocation_reason }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <PaginationControls
                    v-if="pagination.lastPage > 1"
                    :current-page="pagination.currentPage"
                    :last-page="pagination.lastPage"
                    @change="goToPage"
                />
            </div>
        </div>
    </section>
</template>
