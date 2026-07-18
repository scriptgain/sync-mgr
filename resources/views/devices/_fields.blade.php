@php $d = $device ?? null; @endphp
<div x-data="{
        type: '{{ old('endpoint_type', $d->endpoint_type ?? 'ftp') }}',
        port: '{{ old('port', $d->port ?? '') }}',
        defaults: { ftp: 21, sftp: 22, s3: '', agent: 5410, local: '' },
        showSecret: false,
        onType() {
            const known = Object.values(this.defaults).map(String);
            if (this.port === '' || known.includes(String(this.port))) {
                this.port = String(this.defaults[this.type] ?? '');
            }
        },
        is(...types) { return types.includes(this.type); }
     }"
     class="space-y-5">

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Endpoint Name" for="name" required :error="$errors->first('name')">
            <x-input id="name" name="name" required :value="old('name', $d->name ?? '')" placeholder="Production FTP" />
        </x-field>
        <x-field label="Connection Type" for="endpoint_type" required :error="$errors->first('endpoint_type')">
            <div class="relative">
                <select id="endpoint_type" name="endpoint_type" x-model="type" @change="onType()"
                    class="block w-full appearance-none rounded-lg border-0 bg-white pl-3 pr-11 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">
                    @foreach (\App\Models\Device::ENDPOINT_TYPES as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            </div>
        </x-field>
    </div>

    {{-- Agent transport notice --}}
    <div x-show="is('agent')" x-cloak class="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-200">
        <p class="text-sm text-amber-800">Agent transport is coming soon. This endpoint will save, but use FTP, SFTP or S3 for live sync today.</p>
    </div>

    {{-- Host / address + port. Shown for every remote transport. --}}
    <div x-show="! is('local')" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <x-field class="sm:col-span-2" :error="$errors->first('host')">
            <x-slot:label>
                <span x-show="is('ftp','sftp')">Host</span><span x-show="is('s3')">Endpoint / Host</span><span x-show="is('agent')">Device Address</span>
            </x-slot:label>
            <x-input name="host" x-bind:required="! is('local')" :value="old('host', $d->host ?? '')" placeholder="ftp.example.com" />
        </x-field>
        <x-field label="Port" :error="$errors->first('port')" :hint="'Defaults per type; editable.'">
            <input type="number" name="port" min="1" max="65535" x-model="port"
                class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500" />
        </x-field>
    </div>

    {{-- Credentials. Every type captures a username + secret. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field :error="$errors->first('username')">
            <x-slot:label>
                <span x-show="! is('s3')">Username</span><span x-show="is('s3')">Access Key</span>
            </x-slot:label>
            <x-input name="username" :value="old('username', $d->username ?? '')" autocomplete="off" placeholder="user" />
        </x-field>
        <x-field :error="$errors->first('secret')" :hint="$d ? 'Leave blank to keep the stored secret.' : null">
            <x-slot:label>
                <span x-show="! is('s3')">Password</span><span x-show="is('s3')">Secret Key</span>
            </x-slot:label>
            <div class="relative">
                <input name="secret" :type="showSecret ? 'text' : 'password'" autocomplete="new-password" value=""
                    placeholder="{{ $d ? '••••••••' : '' }}"
                    class="block w-full rounded-lg border-0 bg-white pl-3 pr-10 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500" />
                <button type="button" @click="showSecret = ! showSecret" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" tabindex="-1" aria-label="Reveal secret">
                    <x-icon name="eye" class="w-4 h-4" />
                </button>
            </div>
        </x-field>
    </div>

    {{-- S3-only fields. --}}
    <div x-show="is('s3')" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Bucket" :error="$errors->first('bucket')">
            <x-input name="bucket" :value="old('bucket', $d->bucket ?? '')" placeholder="my-bucket" />
        </x-field>
        <x-field label="Region" :error="$errors->first('region')">
            <x-input name="region" :value="old('region', $d->region ?? '')" placeholder="us-east-1" />
        </x-field>
    </div>
    <div x-show="is('s3')" x-cloak>
        <x-toggle name="s3_path_style" label="Force Path-Style Addressing" description="Use path-style URLs (needed by MinIO and most non-AWS S3 gateways)."
            :checked="(bool) old('s3_path_style', $d->s3_path_style ?? false)" />
    </div>

    {{-- Base path. Shown for local/ftp/sftp/s3 (a prefix under the account/bucket). --}}
    <div x-show="is('local','ftp','sftp','s3')" x-cloak>
        <x-field :error="$errors->first('base_path')">
            <x-slot:label>
                <span x-show="is('local')">Local Path</span><span x-show="! is('local')">Base Path</span>
            </x-slot:label>
            <x-input name="base_path" x-bind:required="is('local')" :value="old('base_path', $d->base_path ?? '')" placeholder="/srv/sync/documents" />
            <x-slot:hint>
                <span x-show="is('local')">Absolute path on this server.</span>
                <span x-show="! is('local')">Optional subdirectory under the account root that all pairings are relative to.</span>
            </x-slot:hint>
        </x-field>
    </div>

    {{-- FTP explicit TLS. --}}
    <div x-show="is('ftp')" x-cloak>
        <x-toggle name="ftp_tls" label="Explicit TLS (FTPS)" description="Negotiate TLS with AUTH TLS on the control connection."
            :checked="(bool) old('ftp_tls', $d->ftp_tls ?? false)" />
    </div>

    {{-- SFTP optional private key. --}}
    <div x-show="is('sftp')" x-cloak>
        <x-field label="Private Key (Optional)" :hint="$d ? 'Leave blank to keep the stored key. Overrides password when present.' : 'PEM private key. Overrides the password when present.'" :error="$errors->first('private_key')">
            <textarea name="private_key" rows="4"
                class="block w-full rounded-lg border-0 bg-white px-3 py-2 font-mono text-xs text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
        </x-field>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <x-field label="Status" for="status" required :error="$errors->first('status')">
            <x-select id="status" name="status" required>
                @foreach (\App\Models\Device::STATUSES as $val => $label)
                    <option value="{{ $val }}" @selected(old('status', $d->status ?? 'disconnected') === $val)>{{ $label }}</option>
                @endforeach
            </x-select>
        </x-field>
    </div>

    <x-field label="Notes" for="notes" :error="$errors->first('notes')">
        <textarea id="notes" name="notes" rows="3"
            class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-500"
            placeholder="Optional notes about this endpoint.">{{ old('notes', $d->notes ?? '') }}</textarea>
    </x-field>

    @isset($owners)
        @if ($owners->isNotEmpty())
            <x-field label="Owner" for="owner_id" hint="User who owns this endpoint." :error="$errors->first('owner_id')">
                <x-select id="owner_id" name="owner_id">
                    <option value="">{{ auth()->user()->name }} (me)</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('owner_id', $d->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                    @endforeach
                </x-select>
            </x-field>
            <x-field label="Also Visible To" hint="Extra users who can see this endpoint. Leave empty for the owner and admins only.">
                <x-assignee-picker :users="$owners" :selected="$d?->assignees?->pluck('id')->all() ?? []" />
            </x-field>
        @endif
    @endisset
</div>
