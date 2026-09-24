<script setup>
import { computed } from 'vue';

const props = defineProps({
    currentPage: {
        type: Number,
        required: true,
    },
    lastPage: {
        type: Number,
        required: true,
    },
});

const emit = defineEmits(['change']);

const pageItems = computed(() => {
    const pageNumbers = [...new Set([
        1,
        props.currentPage - 1,
        props.currentPage,
        props.currentPage + 1,
        props.lastPage,
    ])]
        .filter((page) => page >= 1 && page <= props.lastPage)
        .sort((left, right) => left - right);

    return pageNumbers.flatMap((page, index) => {
        const previousPage = pageNumbers[index - 1];
        const pageItem = { type: 'page', value: page, key: `page-${page}` };

        return index > 0 && page - previousPage > 1
            ? [{ type: 'ellipsis', key: `ellipsis-${previousPage}` }, pageItem]
            : [pageItem];
    });
});

function goToPage(page) {
    if (page >= 1 && page <= props.lastPage && page !== props.currentPage) {
        emit('change', page);
    }
}
</script>

<template>
    <nav class="pagination" aria-label="Pagination">
        <button
            class="pagination-control"
            type="button"
            :disabled="currentPage === 1"
            aria-label="Previous page"
            @click="goToPage(currentPage - 1)"
        >
            Previous
        </button>

        <div class="pagination-pages">
            <template v-for="item in pageItems" :key="item.key">
                <span v-if="item.type === 'ellipsis'" class="pagination-ellipsis" aria-hidden="true">…</span>
                <button
                    v-else
                    class="pagination-page"
                    :class="{ 'pagination-page--active': item.value === currentPage }"
                    type="button"
                    :aria-current="item.value === currentPage ? 'page' : undefined"
                    :aria-label="`Page ${item.value}`"
                    @click="goToPage(item.value)"
                >
                    {{ item.value }}
                </button>
            </template>
        </div>

        <span class="pagination-summary">Page {{ currentPage }} of {{ lastPage }}</span>

        <button
            class="pagination-control"
            type="button"
            :disabled="currentPage === lastPage"
            aria-label="Next page"
            @click="goToPage(currentPage + 1)"
        >
            Next
        </button>
    </nav>
</template>
