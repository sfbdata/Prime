---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 5 (parte independente, contrato em docs/specs/assinatura-documentos-etapa-5-motor.md): motor de conferência de PDF já assinado em `App\AssinaturaEletronica\Service\ConferenciaDePdf`, porta em PHP do `bj-assina-pdf.js`. Para cada assinatura confere a faixa de bytes, o resumo (messageDigest) e a chave do certificado (RSA PKCS#1 v1.5, RSA-PSS e ECDSA P-256, P-384 e P-521, só com `openssl`), lê titular, emissor, validade, CPF ou CNPJ da ICP-Brasil, hora declarada e DocMDP, e devolve o veredito com motivo em português; confere também o prefixo byte a byte com a versão anterior e o CPF esperado do signatário.
- Defesas para arquivo de fora: limites de tamanho do PDF, de assinaturas, de profundidade e elementos do DER, de certificados e de inflate (bomba de compressão), faixa de bytes com números absurdos, sobrepostos ou fora do arquivo, e trecho fora da faixa que não é só a assinatura em hexadecimal (fecha o "signature wrapping"). Nenhuma exceção sai do motor. Sem tela, rota, migration nem dependência nova.

## Para o cliente
- A Assinatura de documentos ganhou, por dentro, a conferência de PDF que já chega assinado com certificado digital (gov.br ou ICP-Brasil): o sistema confere se o arquivo mudou depois de cada assinatura e se o certificado é de quem devia assinar. A tela continua desligada e só aparece para a equipe do BlueJus; avisaremos quando estiver liberada.
