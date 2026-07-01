<?php
declare(strict_types=1);
namespace App\Profile\Controller;

use App\Auth\UseCase\VerificarOabUseCase;
use App\Entity\Auth\User;
use App\Profile\DTO\AtualizarFotoInput;
use App\Profile\DTO\AtualizarStatusInput;
use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\DTO\OabPerfilInput;
use App\Profile\DTO\PerfilOutput;
use App\Profile\Form\DadosPessoaisType;
use App\Profile\Form\OabPerfilType;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarDadosPessoaisUseCase;
use App\Profile\UseCase\AtualizarFotoPerfilUseCase;
use App\Profile\UseCase\AtualizarOabPerfilUseCase;
use App\Profile\UseCase\AtualizarStatusUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/perfil', name: 'app_profile')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly AtualizarStatusUseCase $atualizarStatus,
        private readonly AtualizarDadosPessoaisUseCase $atualizarDadosPessoais,
        private readonly AtualizarFotoPerfilUseCase $atualizarFoto,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $fotosPerfilDir,
        private readonly TenantContext $tenantContext,
        private readonly UserProfileRepository $profileRepository,
        private readonly AtualizarOabPerfilUseCase $atualizarOab,
        private readonly VerificarOabUseCase $verificarOab,
        private readonly RateLimiterFactory $oabVerificarLimiter,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userTenant = $this->tenantContext->getCurrentUserTenant();
        $perfil = $this->obterOuCriarPerfil->executar($user);
        $output = PerfilOutput::fromEntity($perfil, $user, $userTenant);

        $input = new DadosPessoaisInput();
        $input->nomeCompleto = $output->nomeCompleto;
        $input->cpf = $output->cpf;
        $input->dataNascimento = $output->dataNascimento;
        $input->ctps = $output->ctps;
        $input->serie = $output->serie;

        $formDados = $this->createForm(DadosPessoaisType::class, $input);

        $oabInput = new OabPerfilInput();
        $oabInput->oabNumero = $output->oabNumero;
        $oabInput->oabUf = $output->oabUf;
        $formOab = $this->createForm(OabPerfilType::class, $oabInput);

        return $this->render('profile/index.html.twig', [
            'perfil'     => $output,
            'form_dados' => $formDados->createView(),
            'form_oab'   => $formOab->createView(),
        ]);
    }

    #[Route('/dados', name: '_dados', methods: ['POST'])]
    public function salvarDados(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);

        $input = new DadosPessoaisInput();
        $form = $this->createForm(DadosPessoaisType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->atualizarDadosPessoais->executar($perfil, $input);
            $this->addFlash('success', 'Dados pessoais atualizados com sucesso.');
        } else {
            $this->addFlash('error', 'Verifique os dados informados.');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/oab', name: '_oab', methods: ['POST'])]
    public function salvarOab(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $input = new OabPerfilInput();
        $form  = $this->createForm(OabPerfilType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Verifique os dados da OAB.');

            return $this->redirectToRoute('app_profile');
        }

        try {
            $this->atualizarOab->executar($user, $input);
            $this->addFlash('success', 'OAB atualizada.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/oab/verificar', name: '_oab_verificar', methods: ['POST'])]
    public function verificarOab(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_oab_verificar', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');

            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($user->getOabNumero() === null || $user->getOabNumero() === '') {
            $this->addFlash('error', 'Informe sua OAB antes de verificar.');

            return $this->redirectToRoute('app_profile');
        }

        if (!$this->oabVerificarLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $this->verificarOab->executar($user);

        // Backend dormente: o resultado é sempre NaoVerificada. Mensagem honesta ao usuário.
        $this->addFlash('info', 'Verificação automática indisponível no momento — sua OAB será revisada por um administrador.');

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/status', name: '_status', methods: ['POST'])]
    public function salvarStatus(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('profile_status', $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);
        $this->atualizarStatus->executar($perfil, new AtualizarStatusInput($request->request->get('status')));

        return new JsonResponse(['ok' => true, 'status' => $perfil->getStatus()]);
    }

    #[Route('/foto', name: '_foto', methods: ['POST'])]
    public function salvarFoto(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('profile_foto', $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $arquivo = $request->files->get('foto');
        if ($arquivo === null) {
            return new JsonResponse(['erro' => 'Nenhuma imagem enviada.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);

        try {
            $this->atualizarFoto->executar($perfil, new AtualizarFotoInput($arquivo));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $urlFoto = $this->generateUrl('app_profile_foto_serve', ['nome' => $perfil->getFotoUrl()]);

        return new JsonResponse(['ok' => true, 'url' => $urlFoto]);
    }

    #[Route('/foto/{nome}', name: '_foto_serve', methods: ['GET'])]
    public function servirFoto(string $nome): Response
    {
        // Rejeita path traversal antes de qualquer acesso ao banco ou filesystem
        if (basename($nome) !== $nome) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $this->profileRepository->buscarPorFotoUrlETenant($nome, $tenant) === null) {
            throw $this->createNotFoundException('Foto não encontrada.');
        }

        $caminho = $this->storage->caminho($this->fotosPerfilDir, $nome);

        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $this->storage->servir($caminho, $nome, inline: true);
    }
}
