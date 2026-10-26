"""Provider identifiers are namespaced values, never Academy access grants."""
from dataclasses import dataclass
import hashlib
import json
import re


@dataclass(frozen=True, slots=True)
class ProviderIdentity:
    """Tenant is configured bot ID / Bitrix tenant / WhatsApp business scope.

    Only a provider verifier may establish authenticity. Constructing this
    value alone proves nothing, and it deliberately has no Moodle user/role.
    """

    provider: str
    tenant: str
    subject: str

    def __post_init__(self):
        if self.provider not in ('telegram', 'bitrix', 'whatsapp'):
            raise ValueError('invalid_provider')
        for value in (self.tenant, self.subject):
            if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,128}', value):
                raise ValueError('invalid_provider_identity')

    def key(self) -> str:
        """Opaque deterministic lookup key; NOT a credential or anonymization."""
        payload = json.dumps([self.provider, self.tenant, self.subject], separators=(',', ':'))
        return hashlib.sha256(('ustar-connect-identity-v1\0' + payload).encode()).hexdigest()
