<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

/**
 * Os modelos fixos da grade "1 · MODELO" (spec `comercial-e-emails.md` §8.2), literais do desenho 05 (`static
 * MODELOS`, dc L.504-515), na ordem do dc:
 *  - os simples (corpo, itens, botão e link): `convite`, `vagas`, `obrigado`, `novidades`;
 *  - o `news` em blocos;
 *  - as cópias em blocos dos 6 modelos de automação (dc L.508-513). São independentes dos templates Twig das
 *    automações da 1a: o que as automações enviam não muda. Do `validar` sai a frase "Ou use o código 482 917 na tela
 *    de confirmação." (Q5, o código é da frente do desenho 10), e o botão `validar` vira `app` no renderizador.
 * As 17 datas comemorativas (F4, spec §13; desenho 05 VS 21.10.26 L626) são os modelos `data-<id>`, montados do
 * {@see CalendarioDasDatasComemorativas}: assunto = título + " · BlueJus", selo = o nome em maiúsculas, título = o
 * título sem o "!" final + ", {nome}!", e os blocos = o texto e o botão "Abrir o BlueJus" para o app. Na grade vêm
 * depois dos fixos, na ordem da próxima data (dc L627, `Object.assign({}, MODELOS, DM, CU)`). Os próprios do Master
 * vêm de `comercial_modelo`.
 *
 * @phpstan-type Bloco array{t: string, a?: string, b?: string}
 * @phpstan-type Modelo array{
 *     chave: string, nome: string, icone: string, modo: string, assunto: string, selo: string, titulo: string,
 *     corpo?: string, itens?: list<string>, cta?: string, link?: string, blocos?: list<Bloco>, html?: string
 * }
 */
final class ModelosDeCampanha
{
    /** @var array<string, Modelo> */
    private const FIXOS = [
        'convite' => ['chave' => 'convite', 'nome' => 'Convite · novos escritórios', 'icone' => 'bi-envelope-heart', 'modo' => 'simples', 'assunto' => 'Seu escritório 100% digital, de graça', 'selo' => 'CONVITE · BLUEJUS', 'titulo' => '{nome}, conheça o escritório digital BlueJus',
            'corpo' => "O BlueJus foi criado por advogados para que nenhum prazo se perca e nenhum processo fique parado.\n\nVocê organiza pastas, clientes, prazos, metas e a equipe em um só lugar, com o Push processual acompanhando os andamentos e a inteligência do sistema avisando o que precisa de atenção.",
            'itens' => ['Controle de prazos e audiências lido das próprias pastas', 'Push processual com resumo por inteligência artificial', 'Documentos, metas e equipe no mesmo lugar', 'Central de vagas e currículos da área jurídica'], 'cta' => 'Criar minha conta grátis', 'link' => 'cadastro'],
        'vagas' => ['chave' => 'vagas', 'nome' => 'Ofertas · vagas abertas', 'icone' => 'bi-briefcase', 'modo' => 'simples', 'assunto' => 'Vagas para Advogados e área Jurídica abertas agora', 'selo' => 'CENTRAL DE VAGAS', 'titulo' => 'Novas vagas na área jurídica',
            'corpo' => "Olá, {nome}!\n\nEscritórios da rede BlueJus estão contratando. Veja as vagas abertas e candidate-se em 1 clique com o seu currículo do BlueJus.", 'itens' => [], 'cta' => 'Ver vagas abertas', 'link' => 'vagas'],
        'obrigado' => ['chave' => 'obrigado', 'nome' => 'Agradecimento · contribuição', 'icone' => 'bi-heart', 'modo' => 'simples', 'assunto' => 'Obrigado por apoiar o BlueJus', 'selo' => 'MUITO OBRIGADO', 'titulo' => '{nome}, o seu apoio faz o BlueJus evoluir',
            'corpo' => "Recebemos a sua contribuição e queremos agradecer de verdade.\n\nEla vai direto para a manutenção, a segurança e as próximas melhorias do sistema. Continue contando com a gente, e conte o que mais ajudaria no dia a dia do seu escritório.", 'itens' => [], 'cta' => 'Enviar uma sugestão', 'link' => 'contribua'],
        'validar' => ['chave' => 'validar', 'nome' => 'Confirmar e-mail', 'icone' => 'bi-envelope-check', 'modo' => 'blocos', 'assunto' => 'Confirme seu e-mail para ativar o BlueJus', 'selo' => 'CONFIRMAÇÃO DE E-MAIL', 'titulo' => '{nome}, falta só confirmar o seu e-mail', 'blocos' => [
            ['t' => 'texto', 'a' => 'Recebemos o cadastro da sua conta no BlueJus. Para proteger o acesso e liberar todas as funções, confirme que este e-mail é seu.'],
            ['t' => 'botao', 'a' => 'Confirmar e criar minha senha', 'b' => 'validar'],
            ['t' => 'texto', 'a' => "O link vale por 7 dias.\n\nSe você não criou esta conta, ignore este e-mail."],
        ]],
        'boasvindas' => ['chave' => 'boasvindas', 'nome' => 'Boas-vindas', 'icone' => 'bi-emoji-smile', 'modo' => 'blocos', 'assunto' => 'Bem-vindo ao BlueJus, seu escritório digital', 'selo' => 'BEM-VINDO(A)', 'titulo' => 'Seja bem-vindo(a), {nome}!', 'blocos' => [
            ['t' => 'texto', 'a' => 'Seu e-mail foi confirmado e a sua conta está pronta. A partir de agora, prazos, processos, documentos e equipe ficam em um só lugar.'],
            ['t' => 'titulo', 'a' => 'Comece por aqui'],
            ['t' => 'noticias', 'a' => "1. Abra sua primeira pasta|Cadastre o cliente e o sistema numera a pasta automaticamente.\n2. Vincule o processo|O Push processual passa a acompanhar os andamentos.\n3. Convide sua equipe|Cada pessoa entra como secundária e você define as permissões."],
            ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app'],
        ]],
        'primeiros' => ['chave' => 'primeiros', 'nome' => 'Primeiros passos (7 dias)', 'icone' => 'bi-signpost-2', 'modo' => 'blocos', 'assunto' => 'Uma semana de BlueJus: dicas para render mais', 'selo' => 'DICAS', 'titulo' => '{nome}, veja o que mais o BlueJus faz por você', 'blocos' => [
            ['t' => 'noticias', 'a' => "Prazos lidos das pastas|Escreva a data no registro e ela vira prazo automaticamente.\nChat I.A|Pergunte quais pastas estão paradas ou o que vence hoje.\nMeu Perfil|Cadastre sua OAB principal e as suplementares."],
            ['t' => 'botao', 'a' => 'Continuar no BlueJus', 'b' => 'app'],
        ]],
        'espaco90' => ['chave' => 'espaco90', 'nome' => 'Espaço em 90%', 'icone' => 'bi-hdd', 'modo' => 'blocos', 'assunto' => 'Seu espaço no BlueJus está quase cheio', 'selo' => 'AVISO DE ESPAÇO', 'titulo' => '{nome}, sua conta chegou a 90% do espaço', 'blocos' => [
            ['t' => 'texto', 'a' => 'O armazenamento do seu escritório no BlueJus está com 90% ocupado. Quando chegar a 100%, o envio de novos documentos fica pausado (consultar e baixar continua liberado).'],
            ['t' => 'noticias', 'a' => "Aumente o espaço|Mude para um plano maior em poucos cliques, sem perder nada.\nOu libere espaço|Use a sugestão de exclusão de documentos repetidos e vazios dentro das pastas."],
            ['t' => 'botao', 'a' => 'Aumentar meu espaço', 'b' => 'espaco'],
        ]],
        'espaco100' => ['chave' => 'espaco100', 'nome' => 'Espaço esgotado', 'icone' => 'bi-hdd-fill', 'modo' => 'blocos', 'assunto' => 'Espaço esgotado: novos envios pausados no BlueJus', 'selo' => 'ESPAÇO ESGOTADO', 'titulo' => '{nome}, o espaço da sua conta acabou', 'blocos' => [
            ['t' => 'texto', 'a' => 'O armazenamento chegou a 100%. Seus arquivos continuam guardados e disponíveis para consulta e download, mas novos envios ficam pausados até ampliar o espaço.'],
            ['t' => 'botao', 'a' => 'Solicitar mais espaço', 'b' => 'espaco'],
        ]],
        'pedido' => ['chave' => 'pedido', 'nome' => 'Pedido de espaço recebido', 'icone' => 'bi-check2-circle', 'modo' => 'blocos', 'assunto' => 'Recebemos seu pedido de mais espaço', 'selo' => 'PEDIDO RECEBIDO', 'titulo' => 'Obrigado, {nome}! Já estamos cuidando disso', 'blocos' => [
            ['t' => 'texto', 'a' => 'Recebemos o seu pedido para ampliar o espaço da conta. A equipe BlueJus vai confirmar o novo plano com você, e nenhum arquivo é apagado enquanto isso.'],
            ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app'],
        ]],
        'news' => ['chave' => 'news', 'nome' => 'Newsletter mensal', 'icone' => 'bi-newspaper', 'modo' => 'blocos', 'assunto' => 'BlueJus em dia · informativo do mês', 'selo' => 'NEWSLETTER · BLUEJUS', 'titulo' => 'Informativo do mês para o seu escritório', 'blocos' => [
            ['t' => 'texto', 'a' => 'Olá, {nome}! Separamos o que mais importa para o seu escritório neste mês.'],
            ['t' => 'titulo', 'a' => 'Destaques'],
            ['t' => 'noticias', 'a' => "Prazos lidos dos registros|O sistema encontra datas de audiência e vencimento dentro das pastas.\nCentral de vagas|Divulgue vagas e receba currículos da área jurídica.\nInscrição OAB suplementar|Cadastre todas as suas inscrições no Meu Perfil."],
            ['t' => 'divisor'],
            ['t' => 'colunas', 'a' => "Dica de gestão\nRevise toda segunda-feira as pastas paradas há mais de 30 dias.", 'b' => "Agenda\nAtualizações do sistema sempre na primeira semana do mês."],
            ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app'],
        ]],
        'novidades' => ['chave' => 'novidades', 'nome' => 'Novidades do sistema', 'icone' => 'bi-stars', 'modo' => 'simples', 'assunto' => 'Novidades no BlueJus deste mês', 'selo' => 'NOVIDADES', 'titulo' => 'O que chegou de novo no BlueJus',
            'corpo' => 'Olá, {nome}! Preparamos melhorias para o seu escritório trabalhar com mais segurança e menos retrabalho:',
            'itens' => ['Marcadores com submarcadores e colunas personalizáveis', 'Prazos e audiências lidos dos registros da pasta', 'Meu Perfil com inscrições OAB principal e suplementar'], 'cta' => 'Abrir o BlueJus', 'link' => 'app'],
    ];

    public const PREFIXO_DE_DATA = 'data-';

    /** @return list<Modelo> na ordem do dc (sem as datas comemorativas: {@see self::datas()}) */
    public function fixos(): array
    {
        return array_values(self::FIXOS);
    }

    /**
     * Os 17 modelos das datas comemorativas: na ordem da próxima data a partir de `$hoje` (dc L626) ou, sem ele, na
     * ordem do calendário do dc.
     *
     * @return list<Modelo>
     */
    public function datas(?\DateTimeImmutable $hoje = null): array
    {
        $chaves = CalendarioDasDatasComemorativas::chaves();
        if ($hoje !== null) {
            $quando = array_map(static fn (string $c): string => CalendarioDasDatasComemorativas::proxima($c, $hoje)->format('Y-m-d'), $chaves);
            array_multisort($quando, SORT_ASC, SORT_STRING, range(0, \count($chaves) - 1), SORT_ASC, $chaves);
        }

        return array_map(self::modeloDaData(...), $chaves);
    }

    /** @return Modelo|null */
    public function fixo(string $chave): ?array
    {
        if (str_starts_with($chave, self::PREFIXO_DE_DATA)) {
            $data = substr($chave, \strlen(self::PREFIXO_DE_DATA));

            return CalendarioDasDatasComemorativas::existe($data) ? self::modeloDaData($data) : null;
        }

        return self::FIXOS[$chave] ?? null;
    }

    public function ehFixo(string $chave): bool
    {
        return $this->fixo($chave) !== null;
    }

    /** @return Modelo o modelo da data (dc L626) */
    private static function modeloDaData(string $data): array
    {
        $d = CalendarioDasDatasComemorativas::data($data);

        return [
            'chave' => self::PREFIXO_DE_DATA . $data, 'nome' => $d['nome'], 'icone' => $d['icone'], 'modo' => 'blocos',
            'assunto' => $d['titulo'] . ' · BlueJus', 'selo' => mb_strtoupper($d['nome']),
            'titulo' => (string) preg_replace('/!$/u', '', $d['titulo']) . ', {nome}!',
            'blocos' => [['t' => 'texto', 'a' => $d['texto']], ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app']],
        ];
    }
}
