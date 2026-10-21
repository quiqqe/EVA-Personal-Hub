<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AuthController extends BaseController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('hub');
        }

        return $this->render('auth/login.html.twig', ['error' => $auth->getLastAuthenticationError(), 'email' => $auth->getLastUsername()]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall');
    }

    #[Route('/register', name: 'register')]
    public function register(Request $r, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, Security $security): Response
    {
        $error = null;
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $email = mb_strtolower(trim((string) $r->request->get('email')));
            $name = trim((string) $r->request->get('name'));
            $password = (string) $r->request->get('password');
            $currency = (string) $r->request->get('currency');
            $error = match (true) {
                !filter_var($email, FILTER_VALIDATE_EMAIL) => 'Некорректный email',
                $name === '' || mb_strlen($name) > 100 => 'Введите имя',
                mb_strlen($password) < 8 => 'Пароль — минимум 8 символов',
                !isset(Money::choices()[$currency]) => 'Выберите валюту',
                (bool) $em->getRepository(User::class)->findOneBy(['email' => $email]) => 'Этот email уже зарегистрирован',
                default => null,
            };
            if (!$error) {
                $user = new User($email, $name, $currency);
                $user->password = $hasher->hashPassword($user, $password);
                $em->persist($user);
                $em->flush();
                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('hub');
            }
        }

        return $this->render('auth/register.html.twig', ['error' => $error]);
    }
}
