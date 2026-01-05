# ============================================
# Colors (using git config for portability)
# ============================================

# Define colors using git's color system
COLOR_RESET := $(shell git config --get-color "" "reset")
COLOR_BOLD := $(shell git config --get-color "" "bold")
COLOR_RED := $(shell git config --get-color "" "red")
COLOR_GREEN := $(shell git config --get-color "" "green")
COLOR_YELLOW := $(shell git config --get-color "" "yellow")
COLOR_BLUE := $(shell git config --get-color "" "blue")
COLOR_CYAN := $(shell git config --get-color "" "cyan")
COLOR_MAGENTA := $(shell git config --get-color "" "magenta")


# Install Composer dependencies and git hooks
setup:
	@echo "$(COLOR_BLUE)🪝 Installing git hooks...$(COLOR_RESET)"
	@if [ -f .githooks/pre-commit ]; then \
		ln -sf ../../.githooks/pre-commit .git/hooks/pre-commit; \
		chmod +x .git/hooks/pre-commit; \
		echo "$(COLOR_GREEN)✅ Git pre-commit hook installed$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  .githooks/pre-commit not found, skipping$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_BLUE)🔧 Making scripts executable...$(COLOR_RESET)"
	@chmod +x scripts/build/generate-changelog.sh scripts/docs/ai-update.sh 2>/dev/null || true
	@echo "$(COLOR_GREEN)✅ Scripts are now executable$(COLOR_RESET)"

