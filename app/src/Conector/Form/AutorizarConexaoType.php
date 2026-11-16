<?php

declare(strict_types=1);

namespace App\Conector\Form;

use App\Conector\DTO\AutorizarConexaoInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * O formulário da tela de autorização do conector (`docs/specs/mcp-remoto-oauth.md`, 9.4).
 *
 * - `tenantId` é um inteiro solto, desenhado no template como uma lista de opções: de propósito, o formulário NÃO
 *   restringe a escolha. Quem confere o escritório contra os vínculos ativos e as barreiras é o servidor
 *   ({@see \App\Conector\UseCase\AutorizarConexaoUseCase}), num lugar só.
 * - CSRF com token de sessão (`conector_autorizar`), e não o `submit` sem estado do resto do sistema, que só confere a
 *   origem: a 9.4 pede o token conferido no POST.
 * - Dois botões com ações diferentes (autorizar e não autorizar): a exceção da regra "botão no template".
 */
final class AutorizarConexaoType extends AbstractType
{
    public const CSRF_TOKEN_ID = 'conector_autorizar';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pedido', HiddenType::class)
            ->add('tenantId', IntegerType::class, ['required' => false])
            ->add('escrita', CheckboxType::class, ['required' => false])
            ->add('autorizar', SubmitType::class)
            ->add('negar', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AutorizarConexaoInput::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }
}
