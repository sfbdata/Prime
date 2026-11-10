"""PKI de TESTE gerada no próprio teste (nada aqui vale fora dos testes).

Raízes, ACs intermediárias, folhas com CPF/CNPJ no otherName da ICP-Brasil, LCR, resposta OCSP e ACT (TSA).
Tudo com a `cryptography`; as conversões para o asn1crypto ficam aqui para o pyHanko usar.
"""

from __future__ import annotations

import datetime as dt
from dataclasses import dataclass, field

from asn1crypto import keys as asn1_keys
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509 import ocsp
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID

AGORA = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
UM_DIA = dt.timedelta(days=1)

URL_LCR = 'http://lcr.teste.invalid/{nome}.crl'
URL_OCSP = 'http://ocsp.teste.invalid/{nome}'


def _chave() -> rsa.RSAPrivateKey:
    return rsa.generate_private_key(public_exponent=65537, key_size=2048)


def _nome(cn: str, ou: str | None = None) -> x509.Name:
    atributos = [
        x509.NameAttribute(NameOID.COUNTRY_NAME, 'BR'),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, 'ICP-Brasil Teste'),
    ]
    if ou:
        atributos.append(x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, ou))
    atributos.append(x509.NameAttribute(NameOID.COMMON_NAME, cn))
    return x509.Name(atributos)


def other_name(oid: str, texto: str) -> x509.OtherName:
    """otherName da ICP-Brasil com o valor em OCTET STRING (como emitem as ACs)."""
    valor = texto.encode('ascii')
    der = bytes([0x04, len(valor)]) + valor
    return x509.OtherName(x509.ObjectIdentifier(oid), der)


def dados_de_pessoa_fisica(cpf: str, nascimento: str = '01011980') -> str:
    """2.16.76.1.3.1 e 2.16.76.1.3.4: nascimento (8), CPF (11), NIS (11), RG (15), órgão e UF (6)."""
    return nascimento + cpf + '0' * 11 + '0' * 15 + '0' * 6


@dataclass
class Entidade:
    certificado: x509.Certificate
    chave: rsa.RSAPrivateKey
    nome: str
    emissor: Entidade | None = None
    revogados: list[x509.Certificate] = field(default_factory=list)

    @property
    def der(self) -> bytes:
        return self.certificado.public_bytes(serialization.Encoding.DER)

    @property
    def pem(self) -> bytes:
        return self.certificado.public_bytes(serialization.Encoding.PEM)

    @property
    def asn1(self) -> asn1_x509.Certificate:
        return asn1_x509.Certificate.load(self.der)

    @property
    def chave_asn1(self) -> asn1_keys.PrivateKeyInfo:
        return asn1_keys.PrivateKeyInfo.load(
            self.chave.private_bytes(
                serialization.Encoding.DER,
                serialization.PrivateFormat.PKCS8,
                serialization.NoEncryption(),
            )
        )

    # Emissão --------------------------------------------------------------------------------------------

    def emitir_ac(self, cn: str, *, com_lcr: bool = False) -> Entidade:
        chave = _chave()
        construtor = (
            x509.CertificateBuilder()
            .subject_name(_nome(cn, self.nome))
            .issuer_name(self.certificado.subject)
            .public_key(chave.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(AGORA - 365 * UM_DIA)
            .not_valid_after(AGORA + 3650 * UM_DIA)
            .add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
            .add_extension(_uso_de_ac(), critical=True)
            .add_extension(x509.SubjectKeyIdentifier.from_public_key(chave.public_key()), critical=False)
            .add_extension(
                x509.AuthorityKeyIdentifier.from_issuer_public_key(self.chave.public_key()), critical=False
            )
        )
        if com_lcr:
            construtor = construtor.add_extension(_pontos_de_lcr(self.nome), critical=False)
        certificado = construtor.sign(self.chave, hashes.SHA256())
        return Entidade(certificado, chave, cn, self)

    def emitir_folha(
        self,
        cn: str,
        *,
        cpf: str | None = None,
        cnpj: str | None = None,
        responsavel_cpf: str | None = None,
        responsavel_nome: str | None = None,
        inicio: dt.datetime | None = None,
        fim: dt.datetime | None = None,
        com_lcr: bool = True,
        com_ocsp: bool = False,
        uso_da_chave: x509.KeyUsage | None = None,
    ) -> Entidade:
        chave = _chave()
        construtor = (
            x509.CertificateBuilder()
            .subject_name(_nome(cn, self.nome))
            .issuer_name(self.certificado.subject)
            .public_key(chave.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(inicio or AGORA - 30 * UM_DIA)
            .not_valid_after(fim or AGORA + 365 * UM_DIA)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .add_extension(uso_da_chave or _uso_de_assinatura(), critical=True)
            .add_extension(
                x509.AuthorityKeyIdentifier.from_issuer_public_key(self.chave.public_key()), critical=False
            )
        )
        nomes: list[x509.GeneralName] = []
        if cpf:
            nomes.append(other_name('2.16.76.1.3.1', dados_de_pessoa_fisica(cpf)))
        if responsavel_nome:
            nomes.append(other_name('2.16.76.1.3.2', responsavel_nome))
        if cnpj:
            nomes.append(other_name('2.16.76.1.3.3', cnpj))
        if responsavel_cpf:
            nomes.append(other_name('2.16.76.1.3.4', dados_de_pessoa_fisica(responsavel_cpf)))
        if nomes:
            construtor = construtor.add_extension(x509.SubjectAlternativeName(nomes), critical=False)
        if com_lcr:
            construtor = construtor.add_extension(_pontos_de_lcr(self.nome), critical=False)
        if com_ocsp:
            construtor = construtor.add_extension(
                x509.AuthorityInformationAccess(
                    [
                        x509.AccessDescription(
                            x509.oid.AuthorityInformationAccessOID.OCSP,
                            x509.UniformResourceIdentifier(URL_OCSP.format(nome=_slug(self.nome))),
                        )
                    ]
                ),
                critical=False,
            )
        certificado = construtor.sign(self.chave, hashes.SHA256())
        return Entidade(certificado, chave, cn, self)

    def emitir_act(self, cn: str = 'ACT Teste BlueJus') -> Entidade:
        chave = _chave()
        certificado = (
            x509.CertificateBuilder()
            .subject_name(_nome(cn, self.nome))
            .issuer_name(self.certificado.subject)
            .public_key(chave.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(AGORA - 30 * UM_DIA)
            .not_valid_after(AGORA + 365 * UM_DIA)
            .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
            .add_extension(_uso_de_assinatura(), critical=True)
            .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.TIME_STAMPING]), critical=True)
            .sign(self.chave, hashes.SHA256())
        )
        return Entidade(certificado, chave, cn, self)

    # Revogação ------------------------------------------------------------------------------------------

    def lcr(self, revogados: list[Entidade] | None = None) -> bytes:
        construtor = (
            x509.CertificateRevocationListBuilder()
            .issuer_name(self.certificado.subject)
            .last_update(AGORA - dt.timedelta(hours=1))
            .next_update(AGORA + UM_DIA)
            .add_extension(x509.CRLNumber(1), critical=False)
            .add_extension(
                x509.AuthorityKeyIdentifier.from_issuer_public_key(self.chave.public_key()), critical=False
            )
        )
        for revogado in revogados or []:
            construtor = construtor.add_revoked_certificate(
                x509.RevokedCertificateBuilder()
                .serial_number(revogado.certificado.serial_number)
                .revocation_date(AGORA - dt.timedelta(hours=2))
                .build()
            )
        return construtor.sign(self.chave, hashes.SHA256()).public_bytes(serialization.Encoding.DER)

    def ocsp(self, folha: Entidade, *, revogado: bool = False) -> bytes:
        """Resposta OCSP assinada pela própria AC emissora."""
        construtor = ocsp.OCSPResponseBuilder().add_response(
            cert=folha.certificado,
            issuer=self.certificado,
            algorithm=hashes.SHA256(),
            cert_status=ocsp.OCSPCertStatus.REVOKED if revogado else ocsp.OCSPCertStatus.GOOD,
            this_update=AGORA - dt.timedelta(minutes=10),
            next_update=AGORA + UM_DIA,
            revocation_time=AGORA - dt.timedelta(hours=2) if revogado else None,
            revocation_reason=x509.ReasonFlags.key_compromise if revogado else None,
        )
        resposta = construtor.responder_id(ocsp.OCSPResponderEncoding.HASH, self.certificado).sign(
            self.chave, hashes.SHA256()
        )
        return resposta.public_bytes(serialization.Encoding.DER)

    def pkcs12(self, senha: bytes, cadeia: list[Entidade]) -> bytes:
        from cryptography.hazmat.primitives.serialization import pkcs12

        return pkcs12.serialize_key_and_certificates(
            name=self.nome.encode('utf-8'),
            key=self.chave,
            cert=self.certificado,
            cas=[c.certificado for c in cadeia],
            encryption_algorithm=serialization.BestAvailableEncryption(senha),
        )


def criar_raiz(cn: str) -> Entidade:
    chave = _chave()
    nome = _nome(cn)
    certificado = (
        x509.CertificateBuilder()
        .subject_name(nome)
        .issuer_name(nome)
        .public_key(chave.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(AGORA - 3650 * UM_DIA)
        .not_valid_after(AGORA + 3650 * UM_DIA)
        .add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
        .add_extension(_uso_de_ac(), critical=True)
        .add_extension(x509.SubjectKeyIdentifier.from_public_key(chave.public_key()), critical=False)
        .sign(chave, hashes.SHA256())
    )
    return Entidade(certificado, chave, cn)


def _slug(nome: str) -> str:
    return ''.join(c if c.isalnum() else '-' for c in nome.lower())


def _pontos_de_lcr(nome_do_emissor: str) -> x509.CRLDistributionPoints:
    return x509.CRLDistributionPoints(
        [
            x509.DistributionPoint(
                full_name=[x509.UniformResourceIdentifier(URL_LCR.format(nome=_slug(nome_do_emissor)))],
                relative_name=None,
                reasons=None,
                crl_issuer=None,
            )
        ]
    )


def _uso_de_ac() -> x509.KeyUsage:
    return x509.KeyUsage(
        digital_signature=True,
        content_commitment=False,
        key_encipherment=False,
        data_encipherment=False,
        key_agreement=False,
        key_cert_sign=True,
        crl_sign=True,
        encipher_only=False,
        decipher_only=False,
    )


def _uso_de_assinatura() -> x509.KeyUsage:
    return x509.KeyUsage(
        digital_signature=True,
        content_commitment=True,
        key_encipherment=False,
        data_encipherment=False,
        key_agreement=False,
        key_cert_sign=False,
        crl_sign=False,
        encipher_only=False,
        decipher_only=False,
    )
