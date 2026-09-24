<script setup>
import AppSidebar from './components/AppSidebar.vue';
import AirportsPage from './pages/AirportsPage.vue';
import AirportEditPage from './pages/AirportEditPage.vue';
import GateSchedulePage from './pages/GateSchedulePage.vue';
import UnallocatedFlightsPage from './pages/UnallocatedFlightsPage.vue';
import AnalyticsPage from './pages/AnalyticsPage.vue';

const match = window.location.pathname.match(/^\/airports\/(\d+)$/);
const airportId = match ? Number(match[1]) : null;
const isGateSchedulePage = window.location.pathname === '/gate-schedule';
const isUnallocatedFlightsPage = window.location.pathname === '/unallocated-flights';
const isAnalyticsPage = window.location.pathname === '/analytics';
</script>

<template>
    <div class="app-shell">
        <AppSidebar />

        <main class="main-content">
            <AirportEditPage v-if="airportId" :airport-id="airportId" />
            <GateSchedulePage v-else-if="isGateSchedulePage" />
            <UnallocatedFlightsPage v-else-if="isUnallocatedFlightsPage" />
            <AnalyticsPage v-else-if="isAnalyticsPage" />
            <AirportsPage v-else />
        </main>
    </div>
</template>
