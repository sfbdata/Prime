---
tipo: novidade
---
## Changelog
- Configurações globais do Master passam a ter efeito: novo leitor `ConfiguracaoDaPlataforma` (valor gravado, senão o padrão do catálogo; nunca derruba a página).
- Cadastro aberto, só por convite ou fechado: com "Só por convite" ou "Fechado", as três portas públicas (e-mail, Google, Facebook) respondem 403 com a mensagem da política e nada é criado; a tela de entrada esconde o "Criar conta" e mostra o aviso em `/cadastro`, `?abrir=cadastro` e `/i/{codigo}`.
- Google e Facebook sem conta, com o cadastro fechado ao público, voltam ao login com o aviso e não guardam a identidade.
- Com "Fechado", também não nascem contas por convite de escritório nem do Trabalho Externo; o convite da plataforma (emitido pelo Master) continua. Login de quem já tem conta não muda. Padrão "Aberto": nada muda.

## Para o cliente
- Quando o Master fecha o cadastro ou o deixa só por convite, a tela de entrada deixa de oferecer "Criar minha conta e escritório" e avisa: "O cadastro está disponível só por convite." ou "O cadastro está fechado no momento." Quem já tem conta entra normalmente.
