#!/bin/bash

tmux-workspace "ServiceWorkerGenerator" "editor" -c "nvim && zsh" \
	-w "server" -c "php -S localhost:7353"	
