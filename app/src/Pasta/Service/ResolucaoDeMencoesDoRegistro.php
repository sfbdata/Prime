<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Processo\Entity\NotaTecnica;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A parte das @menções que depende do banco — usada IGUAL no envio
 * (`EnviarMensagemPastaUseCase`) e na edição (`EditarMensagemPastaUseCase`) do Registro, e na
 * criação e edição das NOTAS TÉCNICAS (`CriarNotaTecnicaUseCase` / `EditarNotaTecnicaUseCase`,
 * PMC-019), para que nenhum caminho grave um token que outro recusaria.
 *
 * O nome ficou "do Registro" porque nasceu lá; as regras (quem é mencionável, quem recebe, a
 * anti-duplicata e a cota) são as mesmas nas duas origens e moram num lugar só — o
 * {@see avisar()}. O que muda por origem é só o tipo, o link e o rótulo da aba, montados em
 * {@see notificar()} (mensagem do Registro) e {@see notificarNaNotaTecnica()}.
 *
 * `resolver()` — confere cada id dos tokens contra os colegas com vínculo ATIVO neste escritório
 * (e usuário ativo), numa consulta só presa ao tenant. Id válido: rótulo trocado pelo nome do
 * banco. Id inexistente, de outro escritório, de ex-colaborador ou além do teto
 * ({@see MencoesDoRegistro::MAXIMO_POR_REGISTRO}): a marcação cai e fica `@rótulo` em texto comum.
 *
 * `notificar()` — `TIPO_PASTA_MENCAO_REGISTRO` para cada mencionado válido: "<Primeiro nome>
 * mencionou você na Pasta N · Dados da pasta" (desenho `menNotificar`), prévia de 180, link
 * `#pasta-msg-<id>`. Nunca o autor nem quem `$pular` indicar (precedência da resposta); só quem
 * tem acesso de leitura a ESTA pasta; anti-duplicata por destinatário + escritório + tipo + link —
 * o link é o da mensagem, então quem já foi avisado por ela (no envio ou numa edição anterior)
 * não recebe de novo. Por último, a cota do autor ({@see CotaDeNotificacoesDoRegistro}, SEC-008):
 * estourada, o aviso é suprimido e a mensagem segue gravada — no envio e na edição.
 *
 * `notificarNaObservacao()` — @menções nas observações de Detalhes e no Relatório financeiro
 * (EDT-010r): o mesmo `TIPO_PASTA_MENCAO_REGISTRO` e as mesmas regras do Registro, com o link
 * da âncora do cartão e o rótulo da aba ("· Detalhes", "· Financeiro").
 *
 * `notificarRespostaNaObservacao()` — o "respondeu seu comentário" do Relatório financeiro
 * (FPN-005) e das observações de Detalhes (PAS-039), com as MESMAS regras do Registro (`EnviarMensagemPastaUseCase`): nunca quem
 * respondeu; só quem ainda tem vínculo ATIVO neste escritório e VÊ esta pasta; um aviso por
 * resposta (o link é o da resposta); a mesma cota do autor.
 *
 * Relatório financeiro (C6-SEC-02): menção e resposta só avisam quem, além de ver a pasta, VÊ O
 * FINANCEIRO dela ({@see VisibilidadeDoFinanceiroDaPasta}) — a prévia no sino leva o texto do
 * registro financeiro. Hoje as duas perguntas dão a mesma resposta; no dia em que o financeiro
 * ganhar permissão própria, o aperto entra lá e o sino já obedece.
 */
final class ResolucaoDeMencoesDoRegistro
{
    /** Onde o Registro mora na pasta_show — rótulo do desenho (`abaRot = 'Dados da pasta'`). */
    public const ABA_DO_REGISTRO = 'Dados da pasta';

    /** Onde a nota técnica mora na pasta_show — `menAbaRot.processo` do desenho. */
    public const ABA_DO_PROCESSO = 'Processo';

    /** Nota escrita sobre uma movimentação, no teor do Push — `menAbaRot.push` do desenho. */
    public const ABA_DO_PUSH = 'Push Processual';

    /** Relatório financeiro — rótulo do desenho (`regsLinha(…, 'Financeiro', 'financeiro')`). */
    public const ABA_DO_FINANCEIRO = 'Financeiro';

    /** Relatório inicial de Atendimento — rótulo do desenho (`regsLinha(obsDet, 'Detalhes', …)`). */
    public const ABA_DE_DETALHES = 'Detalhes';

    /** Texto do "respondeu seu comentário" — desenho `bluejus-central.js` `notificarResposta`. */
    private const LIMITE_RESPOSTA = 140;
    private const LIMITE_ORIGINAL = 80;

    /** Prévia da menção no sino — `menNotificar`: `prev.slice(0, 180) + '…'`. */
    private const LIMITE_PREVIA = 180;

    public function __construct(
        private readonly MencoesDoRegistro $mencoes,
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly NotificacaoService $notificacaoService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CotaDeNotificacoesDoRegistro $cota,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
    ) {
    }

    /**
     * Recebe conteúdo JÁ sanitizado.
     *
     * @return array{0: string, 1: list<User>} o conteúdo na forma canônica e os mencionados válidos
     *                                         (na ordem em que aparecem)
     */
    public function resolver(string $conteudo, Tenant $tenant): array
    {
        $ids = $this->mencoes->extrairIds($conteudo);
        if ($ids === []) {
            return [$conteudo, []];
        }

        $validos = [];
        $nomes   = [];
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            $id = $colega->getId();
            if ($id === null || !in_array($id, $ids, true) || !$colega->isActive()) {
                continue;
            }
            $validos[$id] = $colega;
            $nomes[$id]   = (string) $colega->getFullName();
        }

        $mencionados = [];
        foreach ($ids as $id) {
            if (isset($validos[$id])) {
                $mencionados[] = $validos[$id];
            }
        }

        return [$this->mencoes->reescrever($conteudo, $nomes), $mencionados];
    }

    /**
     * Chamar DEPOIS do flush (o link leva o id da mensagem) e dentro da transação da mensagem.
     *
     * @param list<User> $mencionados saída de {@see resolver()}
     * @param User|null  $pular       quem já recebe aviso mais específico por esta mensagem (o autor
     *                                do comentário respondido)
     */
    public function notificar(array $mencionados, PastaMensagem $mensagem, Pasta $pasta, User $autor, Tenant $tenant, ?User $pular = null): void
    {
        $pastaId = $pasta->getId();
        if ($mencionados === [] || $pastaId === null || $mensagem->getId() === null) {
            return;
        }

        $this->avisar(
            $mencionados,
            $pasta,
            $autor,
            $tenant,
            Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
            $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#pasta-msg-' . $mensagem->getId(),
            self::ABA_DO_REGISTRO,
            $mensagem->getConteudo(),
            $mensagem,
            $pular,
        );
    }

    /**
     * @menções de uma nota técnica (PMC-019). A nota é do PROCESSO, mas foi escrita numa PASTA — é
     * por ela que se decide quem recebe (acesso de leitura a esta pasta, como no Registro) e para
     * onde o link leva: `pasta_show#nota-tecnica-<id>`, a âncora do cartão na aba Processo (lá a
     * nota aparece sempre, inclusive a pendurada numa movimentação; o nota-tecnica.js abre a aba).
     * O rótulo da aba segue o desenho (`menAbaRot`): "Processo", ou "Push Processual" quando a nota
     * foi escrita sobre uma movimentação.
     *
     * Tipo próprio (`TIPO_NOTA_TECNICA_MENCAO`), com as mesmas regras do Registro: nunca o autor;
     * só quem VÊ a pasta; anti-duplicata por destinatário + escritório + tipo + link (o link é o
     * da nota, então a edição só avisa quem passou a ser mencionado); cota do autor.
     *
     * Chamar DEPOIS do flush (o link leva o id da nota) e dentro da transação da nota. A pasta
     * tem de ser deste escritório E vincular o processo da nota — quem chama já conferiu; aqui a
     * prova é repetida, porque um aviso com link para pasta alheia vazaria o nome dela.
     *
     * @param list<User> $mencionados saída de {@see resolver()}
     */
    public function notificarNaNotaTecnica(array $mencionados, NotaTecnica $nota, Pasta $pasta, User $autor, Tenant $tenant): void
    {
        $pastaId = $pasta->getId();
        if ($mencionados === [] || $pastaId === null || $nota->getId() === null) {
            return;
        }

        $donoDaPasta = $pasta->getTenant();
        if ($donoDaPasta === null || !self::mesmoTenant($donoDaPasta, $tenant) || !$pasta->temProcesso($nota->getProcesso())) {
            return;
        }

        $this->avisar(
            $mencionados,
            $pasta,
            $autor,
            $tenant,
            Notificacao::TIPO_NOTA_TECNICA_MENCAO,
            $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#nota-tecnica-' . $nota->getId(),
            $nota->getPublicacaoDjen() !== null ? self::ABA_DO_PUSH : self::ABA_DO_PROCESSO,
            $nota->getConteudo(),
            $nota,
            null,
        );
    }

    /**
     * "<Primeiro nome> respondeu seu comentário" para o autor da RAIZ de uma resposta do
     * Relatório financeiro (FPN-005) ou das observações de Detalhes (PAS-039). Tipo
     * `TIPO_PASTA_RESPOSTA_REGISTRO` — o mesmo do Registro: o sino não distingue a aba, e o texto
     * já diz qual é ("· em Financeiro da Pasta N"). Link `pasta_show#<âncora>-<idDaResposta>`
     * ({@see ancoraDaObservacao()}): cada resposta tem o seu.
     *
     * Chamar DEPOIS do flush (o link leva o id da resposta) e dentro da transação dela. A pasta,
     * a raiz e a resposta têm de ser deste escritório e a raiz desta pasta — quem chama já
     * conferiu; aqui a prova é repetida, porque um aviso com link para pasta alheia vazaria o
     * nome dela.
     */
    public function notificarRespostaNaObservacao(
        PastaObservacaoFinanceira|PastaObservacaoDetalhes $raiz,
        PastaObservacaoFinanceira|PastaObservacaoDetalhes $resposta,
        Pasta $pasta,
        User $quemResponde,
        Tenant $tenant,
    ): void {
        // Raiz e resposta da MESMA origem: uma resposta de Detalhes nunca pendura num registro
        // financeiro (o link e o rótulo da aba sairiam trocados).
        if ($raiz::class !== $resposta::class) {
            return;
        }

        $destinatario = $raiz->getAutor();
        $pastaId      = $pasta->getId();
        if ($destinatario === null || $pastaId === null || $resposta->getId() === null || self::mesmoUsuario($destinatario, $quemResponde)) {
            return;
        }

        foreach ([$pasta->getTenant(), $raiz->getTenant(), $resposta->getTenant()] as $dono) {
            if ($dono === null || !self::mesmoTenant($dono, $tenant)) {
                return;
            }
        }
        if ($raiz->getPasta() === null || $raiz->getPasta()->getId() !== $pastaId) {
            return;
        }

        if (!$this->temVinculoAtivo($destinatario, $tenant)
            || !$this->permissionChecker->canAccessResource($destinatario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            return;
        }

        // C6-SEC-02: o texto da resposta e o do comentário vão para o sino — no Relatório
        // financeiro, só para quem vê o financeiro desta pasta.
        if ($raiz instanceof PastaObservacaoFinanceira && !$this->visibilidadeDoFinanceiro->podeVer($destinatario, $tenant, $pasta)) {
            return;
        }

        $tipo = Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO;
        $url  = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . self::ancoraDaObservacao($resposta);

        // Idempotência da MESMA resposta: o link é único por resposta.
        $jaExiste = $this->notificacaoRepository->findOneBy([
            'usuario' => $destinatario,
            'tenant'  => $tenant,
            'tipo'    => $tipo,
            'url'     => $url,
        ]);
        if ($jaExiste !== null) {
            return;
        }

        // Depois dos filtros: só aviso que de fato sairia gasta a cota de quem respondeu.
        if (!$this->cota->permite($quemResponde, $destinatario, $tipo, $resposta)) {
            return;
        }

        $texto = sprintf(
            '"%s" · em %s da Pasta %s · %s. Seu comentário: "%s"',
            mb_substr($this->mencoes->textoDoSino($resposta->getConteudo()), 0, self::LIMITE_RESPOSTA),
            $resposta instanceof PastaObservacaoDetalhes ? self::ABA_DE_DETALHES : self::ABA_DO_FINANCEIRO,
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            $resposta->getCriadaEm()->format('d/m/Y H:i'),
            mb_substr($this->mencoes->textoDoSino($raiz->getConteudo()), 0, self::LIMITE_ORIGINAL),
        );

        $this->notificacaoService
            ->criar($destinatario, $tenant, $tipo, sprintf('%s respondeu seu comentário', self::primeiroNome($quemResponde)), $texto)
            ->setUrl($url);
    }

    /**
     * A âncora do cartão na pasta_show: `#financeiro-obs-<id>` (`_financeiro.html.twig`) ou
     * `#detalhes-obs-<id>` (`_detalhes_obs.html.twig`). É também por ela que a exclusão apaga o
     * aviso (`excluirPelaAncoraDoLink`).
     */
    public static function ancoraDaObservacao(PastaObservacaoFinanceira|PastaObservacaoDetalhes $obs): string
    {
        return ($obs instanceof PastaObservacaoDetalhes ? '#detalhes-obs-' : '#financeiro-obs-') . $obs->getId();
    }

    /**
     * Vínculo ATIVO neste escritório e usuário ativo — o mesmo critério de quem é mencionável
     * ({@see resolver()}), pela mesma consulta presa ao tenant.
     */
    private function temVinculoAtivo(User $pessoa, Tenant $tenant): bool
    {
        if (!$pessoa->isActive()) {
            return false;
        }
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            if (self::mesmoUsuario($colega, $pessoa)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @menções de uma observação de Detalhes ou de um registro do Relatório financeiro
     * (EDT-010r). Tipo `TIPO_PASTA_MENCAO_REGISTRO` — o do Registro: o sino não distingue a aba,
     * o título já diz qual é ("… na Pasta N · Financeiro"). Link `pasta_show#<âncora>-<id>`
     * ({@see ancoraDaObservacao()}): a anti-duplicata é por link, então a edição só avisa quem
     * passou a ser mencionado.
     *
     * Chamar DEPOIS do flush (o link leva o id) e dentro da transação da observação. A pasta e a
     * observação têm de ser deste escritório e a observação desta pasta — quem chama já
     * conferiu; aqui a prova é repetida (um aviso com link para pasta alheia vazaria o nome dela).
     *
     * @param list<User> $mencionados saída de {@see resolver()}
     * @param User|null  $pular       o autor da observação respondida (ele já recebe a resposta)
     */
    public function notificarNaObservacao(
        array $mencionados,
        PastaObservacaoFinanceira|PastaObservacaoDetalhes $obs,
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        ?User $pular = null,
    ): void {
        $pastaId = $pasta->getId();
        if ($mencionados === [] || $pastaId === null || $obs->getId() === null) {
            return;
        }

        foreach ([$pasta->getTenant(), $obs->getTenant()] as $dono) {
            if ($dono === null || !self::mesmoTenant($dono, $tenant)) {
                return;
            }
        }
        if ($obs->getPasta() === null || $obs->getPasta()->getId() !== $pastaId) {
            return;
        }

        $this->avisar(
            $mencionados,
            $pasta,
            $autor,
            $tenant,
            Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
            $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . self::ancoraDaObservacao($obs),
            $obs instanceof PastaObservacaoDetalhes ? self::ABA_DE_DETALHES : self::ABA_DO_FINANCEIRO,
            $obs->getConteudo(),
            $obs,
            $pular,
        );
    }

    /**
     * O núcleo comum às duas origens: título do desenho (`menNotificar`), prévia de 180 e, para
     * cada mencionado, os filtros na ordem — o autor e `$pular`, o acesso de leitura à pasta, a
     * visibilidade do financeiro (só no Relatório financeiro), a anti-duplicata e, por último, a
     * cota (só aviso que de fato sairia gasta a cota do autor).
     *
     * @param list<User> $mencionados
     */
    private function avisar(
        array $mencionados,
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        string $tipo,
        string $url,
        string $aba,
        ?string $conteudo,
        PastaMensagem|NotaTecnica|PastaObservacaoFinanceira|PastaObservacaoDetalhes $origem,
        ?User $pular,
    ): void {
        $pastaId = (int) $pasta->getId();
        $titulo  = sprintf(
            '%s mencionou você na Pasta %s · %s',
            self::primeiroNome($autor),
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            $aba,
        );
        $previa = $this->mencoes->textoDoSino($conteudo);
        if (mb_strlen($previa) > self::LIMITE_PREVIA) {
            $previa = mb_substr($previa, 0, self::LIMITE_PREVIA) . '…';
        }

        foreach ($mencionados as $pessoa) {
            if (self::mesmoUsuario($pessoa, $autor) || ($pular !== null && self::mesmoUsuario($pessoa, $pular))) {
                continue;
            }

            if (!$this->permissionChecker->canAccessResource($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
                continue;
            }

            // C6-SEC-02: a prévia leva o texto do registro — no Relatório financeiro, só a quem
            // vê o financeiro desta pasta.
            if ($origem instanceof PastaObservacaoFinanceira && !$this->visibilidadeDoFinanceiro->podeVer($pessoa, $tenant, $pasta)) {
                continue;
            }

            $jaExiste = $this->notificacaoRepository->findOneBy([
                'usuario' => $pessoa,
                'tenant'  => $tenant,
                'tipo'    => $tipo,
                'url'     => $url,
            ]);
            if ($jaExiste !== null) {
                continue;
            }

            // Depois dos filtros: só aviso que de fato sairia gasta a cota do autor.
            if (!$this->cota->permite($autor, $pessoa, $tipo, $origem)) {
                continue;
            }

            $this->notificacaoService
                ->criar($pessoa, $tenant, $tipo, $titulo, $previa)
                ->setUrl($url);
        }
    }

    private static function primeiroNome(User $usuario): string
    {
        $nome = trim((string) $usuario->getFullName());

        return $nome === '' ? 'Alguém' : explode(' ', $nome)[0];
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    private static function mesmoTenant(Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
