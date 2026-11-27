@pushOnce('scripts')
<script type="module">
    window.damDownloadDirectoryZip = function (vm, directoryId) {
        const url = `{{ route('admin.dam.directory.zip_download', ':id') }}`.replace(':id', directoryId);

        vm.$axios.get(url)
            .then(() => { window.location.href = url; })
            .catch(e => vm.$emitter.emit('add-flash', {
                type: e?.response?.status === 422 ? 'warning' : 'error',
                message: e?.response?.data?.message ?? "@lang('dam::app.admin.dam.index.directory.failed-download-directory')",
            }));
    };
</script>
@endPushOnce
